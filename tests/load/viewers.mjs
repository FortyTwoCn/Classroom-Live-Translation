// Actual wall-clock PCM pacing, real PHP/PostgreSQL/WebSocket; explicit mock upstream and SDK storage.
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync,appendFileSync,existsSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {performance} from 'node:perf_hooks';
import {hostname,cpus,totalmem,platform,release} from 'node:os';
const require=createRequire(new URL('../../frontend/package.json',import.meta.url));
const WebSocket=require('ws');
const [fixturePath,durationText,viewerText,reportPath,phpPath,runPath]=process.argv.slice(2);
const duration=Number(durationText),viewerCount=Number(viewerText);
if(process.env.CLASSROOM_LOAD_PROFILE!=='1'||process.env.APP_ENV!=='testing'||!/^classroom_load_[a-f0-9]{12}$/.test(process.env.DB_DATABASE||'')||!Number.isInteger(duration)||duration<60||duration>7200||!Number.isInteger(viewerCount)||viewerCount<1||viewerCount>200)throw Error('isolated_load_profile_required');
const fixture=JSON.parse(readFileSync(fixturePath,'utf8')),origin='http://127.0.0.1:28080',wsUrl='ws://127.0.0.1:28081';
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
const sockets=[],failures=[],clients=[],ackLatencies=[],broadcastLatencies=[],sendTimes=new Map(),pending=new Map();
let recorder,stopping=false,ackCount=0,duplicateSent=0,framesSent=0,audioStarted=0,firstEvent=Infinity,lastEvent=0;
const summary=values=>{const a=values.filter(Number.isFinite).sort((x,y)=>x-y);const q=p=>a.length?a[Math.min(a.length-1,Math.floor((a.length-1)*p))]:null;return{count:a.length,min_ms:a[0]??null,p50_ms:q(.5),p95_ms:q(.95),p99_ms:q(.99),max_ms:a.at(-1)??null};};
async function request(jar,path,method='GET',data,csrf){const headers={Origin:origin,Accept:'application/json'};if(jar.size)headers.Cookie=[...jar].map(([k,v])=>`${k}=${v}`).join('; ');if(csrf)headers['X-CSRF-TOKEN']=csrf;if(data!==undefined)headers['Content-Type']='application/json';const res=await fetch(origin+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data)});for(const cookie of res.headers.getSetCookie()){const first=cookie.split(';')[0],index=first.indexOf('=');jar.set(first.slice(0,index),first.slice(index+1));}let result;try{result=await res.json();}catch{throw Error('load_http_invalid_json');}if(!res.ok)throw Error(`load_http_${res.status}_${result.error||'failed'}`);return result;}
async function connect(index,isRecorder=false){const jar=new Map();let me=await request(jar,'/api/me');if(isRecorder){await request(jar,'/api/login','POST',{student_id:fixture.student_id,password:fixture.password,remember:false},me.csrf_token);me=await request(jar,'/api/me');}const ticket=await request(jar,`/api/lessons/${fixture.lesson_id}/ticket`,'POST',{},me.csrf_token);
 const client={index,jar,seen:new Set(),units:new Set(),events:0,duplicates:0,errors:[],snapshot:false,ready:null};clients.push(client);
 const socket=new WebSocket(wsUrl,{origin});client.socket=socket;sockets.push(socket);
 socket.on('message',bytes=>{const e=JSON.parse(bytes.toString()),now=Date.now();if(e.type==='snapshot')client.snapshot=true;if(e.type==='recorder.ready')client.ready=e;if(e.type==='error'){client.errors.push(e.code);failures.push(`client_${index}:${e.code}`);}if(e.type==='audio.ack'){ackCount++;const q=pending.get(e.sequence);if(!q?.length){failures.push('unexpected_ack');return;}ackLatencies.push(now-q.shift());if(q.length===0)pending.delete(e.sequence);}
  if(e.type==='event'){client.events++;firstEvent=Math.min(firstEvent,now);lastEvent=Math.max(lastEvent,now);if(client.seen.has(e.seq))client.duplicates++;client.seen.add(e.seq);if(e.event_type==='subtitle.updated'&&e.payload?.unit?.status==='completed'){const unit=e.payload.unit;if(!client.units.has(unit.id)){client.units.add(unit.id);const match=(unit.text||'').match(/\[LOAD #(\d+) @(\d+)\]/);if(match)broadcastLatencies.push(now-Number(match[2]));}}}
 });socket.on('error',()=>{if(!stopping)failures.push(`client_${index}:socket_error`);});socket.on('close',(code,reason)=>{if(!stopping){failures.push(`client_${index}:unexpected_close_${code}`);appendFileSync(runPath+'/connection-diagnostics.ndjson',JSON.stringify({type:'unexpected_close',client:index,at_ms:Date.now(),code,reason:reason.toString().slice(0,80)})+'\n');}});
 await new Promise((resolve,reject)=>{socket.once('open',()=>{socket.send(JSON.stringify({type:'subscribe',ticket:ticket.ticket,last_seq:0}));resolve();});socket.once('error',()=>reject(Error('load_ws_connect_failed')));});
 const deadline=Date.now()+20000;while(!client.snapshot){if(Date.now()>deadline)throw Error('load_snapshot_timeout');await sleep(20);}client.heartbeat=setInterval(()=>{if(client.socket.readyState===WebSocket.OPEN)client.socket.send(JSON.stringify({type:'heartbeat'}));},5000);appendFileSync(runPath+'/connection-diagnostics.ndjson',JSON.stringify({type:'subscribed',client:index,at_ms:Date.now()})+'\n');return client;
}
function sendFrame(sequence,serial,duplicate=false){const frame=Buffer.alloc(3208);frame.writeUInt32BE(recorder.ready.epoch,0);frame.writeUInt32BE(sequence,4);for(let n=0;n<1600;n++)frame.writeInt16LE(Math.round(4000*Math.sin((serial*1600+n)*2*Math.PI*440/16000)),8+n*2);frame.writeUInt32LE(serial,8);const now=Date.now();if(!pending.has(sequence))pending.set(sequence,[]);pending.get(sequence).push(now);if(!duplicate)sendTimes.set(serial,now);recorder.socket.send(frame);if(duplicate)duplicateSent++;else framesSent++;}
const startedAt=new Date().toISOString();let finalMetrics=null,wallSeconds=0,error=null,archiveWaitSeconds=null;
try{
 recorder=await connect(-1,true);for(let i=0;i<viewerCount;i++)await connect(i);
 recorder.socket.send(JSON.stringify({type:'start'}));const deadline=Date.now()+25000;while(!recorder.ready){if(failures.length||Date.now()>deadline)throw Error('load_recorder_not_ready');await sleep(20);}
 audioStarted=Date.now();const clockStart=performance.now(),count=duration*10;
 writeFileSync(runPath+'/progress.json',JSON.stringify({status:'running',started_at:new Date().toISOString(),duration_seconds:duration,viewers:viewerCount,frames_sent:0}));
 for(let seq=0;seq<count;seq++){
  await sleep(Math.max(0,clockStart+(seq+1)*100-performance.now()));
  if(failures.length)throw Error('load_stream_failure');if(recorder.socket.readyState!==WebSocket.OPEN)throw Error('load_recorder_closed');
  sendFrame(seq,seq,false);
  if(seq===Math.floor(count/2))sendFrame(seq,seq,true);
  if(seq%100===99)writeFileSync(runPath+'/progress.json',JSON.stringify({status:'running',duration_seconds:duration,elapsed_seconds:(Date.now()-audioStarted)/1000,viewers:viewerCount,frames_sent:framesSent,acks:ackCount}));
 }
 wallSeconds=(Date.now()-audioStarted)/1000;
 const ackDeadline=Date.now()+15000;while(pending.size){if(Date.now()>ackDeadline)throw Error('load_ack_timeout');await sleep(50);}
 recorder.socket.send(JSON.stringify({type:'end',epoch:recorder.ready.epoch,confirmed:true}));
 await sleep(3000);
 // The same guarded mock-SDK worker exercises real FFmpeg after end, with bounded waiting.
 const archiveWaitStarted=Date.now(),archiveDeadline=archiveWaitStarted+1800000;
 do{const m=spawnSync(phpPath,['tests/load/metrics.php','--final'],{encoding:'utf8',cwd:new URL('../..',import.meta.url)});if(m.status!==0)throw Error('load_db_metrics_failed');finalMetrics=JSON.parse(m.stdout);if(finalMetrics.lesson.archive_status==='playable'&&finalMetrics.queued_jobs===0&&Object.entries(finalMetrics.chunks).every(([state,count])=>state==='uploaded'||count===0))break;await sleep(1000);}while(Date.now()<archiveDeadline);
 archiveWaitSeconds=(Date.now()-archiveWaitStarted)/1000;
 if(finalMetrics.lesson.archive_status!=='playable')failures.push('archive_not_playable');
 if(finalMetrics.lesson.status!=='ended')failures.push('lesson_not_ended');
 if(framesSent!==duration*10||ackCount!==framesSent+duplicateSent||pending.size)failures.push('durable_ack_count_mismatch');
 if(finalMetrics.segments.frames!==framesSent||Number(finalMetrics.segments.samples)!==framesSent*1600||finalMetrics.lesson.media_samples!==framesSent*1600)failures.push('sample_or_segment_count_mismatch');
 if(finalMetrics.segments.model_sent!==framesSent||finalMetrics.timeline.gaps!==0)failures.push('delivery_or_timeline_mismatch');
 if(finalMetrics.failed_jobs!==0||Object.entries(finalMetrics.chunks).some(([state,count])=>state!=='uploaded'&&count>0))failures.push('queue_or_chunk_failure');
 if(clients.some(c=>c.duplicates||c.units.size!==finalMetrics.completed_units))failures.push('viewer_delivery_mismatch');
}catch(e){error=e.message;failures.push(error);wallSeconds=audioStarted?(Date.now()-audioStarted)/1000:0;}
finally{stopping=true;for(const c of clients){clearInterval(c.heartbeat);c.socket.close();}}
let mock=[],uploads=[];for(const [file,rows]of[['mock-metrics.ndjson',mock],['upload-metrics.ndjson',uploads]])if(existsSync(runPath+'/'+file))for(const line of readFileSync(runPath+'/'+file,'utf8').trim().split('\n'))if(line)rows.push(JSON.parse(line));
const audio=mock.filter(r=>r.type==='audio'),responses=mock.filter(r=>r.type==='response');
const forward=audio.map(r=>r.at_ms-sendTimes.get(r.serial)),responseDelay=responses.map(r=>r.at_ms-sendTimes.get(r.serial));
const mockDuplicates=audio.length-new Set(audio.map(r=>r.serial)).size;
if(mockDuplicates)failures.push('duplicate_audio_forwarded');
const report={classification:'REAL_WALL_CLOCK_PHP_POSTGRESQL_WEBSOCKET_SIMULATED_QWEN_AND_S3',status:failures.length?'failed':'passed',started_at:startedAt,finished_at:new Date().toISOString(),requested_duration_seconds:duration,actual_capture_wall_seconds:wallSeconds,viewers:{kind:'Node ws clients; not browser tabs or real devices',requested:viewerCount,connected:clients.filter(c=>c.index>=0&&c.snapshot).length,min_events:clients.length?Math.min(...clients.map(c=>c.events)):0,max_events:clients.length?Math.max(...clients.map(c=>c.events)):0,duplicates:clients.reduce((n,c)=>n+c.duplicates,0),completed_unit_delivery_counts:clients.map(c=>({client:c.index,units:c.units.size}))},capture:{artificial_pcm:true,format:'16000Hz mono PCM16 little-endian',frame_ms:100,frames_sent:framesSent,duplicate_frames_sent:duplicateSent,acks:ackCount,samples:framesSent*1600,real_microphone:false},mock_model:{audio_received:audio.length,duplicate_audio_received:mockDuplicates,responses:responses.length,sessions:mock.filter(r=>r.type==='connected').length,real_model:false},storage:{sdk_handler:'explicit test-process file-backed command fixture',real_oss:false,real_ffmpeg:finalMetrics?.lesson.archive_status==='playable',metrics:finalMetrics},latencies:{durable_ack:summary(ackLatencies),forward_to_mock:summary(forward),mock_response_from_last_frame:summary(responseDelay),completed_subtitle_broadcast_to_all_clients:summary(broadcastLatencies),mock_sdk_commands:summary(uploads.map(r=>r.duration_ms)),clock:'same-host Date.now()/PHP microtime; wall-clock offset not network synchronized'},machine:{platform:platform(),os_release:release(),cpu:cpus()[0]?.model,logical_cpus:cpus().length,total_memory_bytes:totalmem(),node:process.version},failures:[...new Set(failures)],unverified:['real Qwen capacity/quota','real OSS throughput','real microphone and browsers/mobile devices','Linux Docker deployment/timeouts'],resource_samples_path:'work load profile resource-metrics.ndjson (private runtime)' };
const readRows=file=>existsSync(file)?readFileSync(file,'utf8').trim().split('\n').filter(Boolean).map(line=>JSON.parse(line)):[];
const resources=readRows(runPath+'/resource-metrics.ndjson'),databaseSamples=readRows(runPath+'/database-metrics.ndjson');
const processNames=[...new Set(resources.flatMap(r=>r.processes.map(p=>p.name)))];
report.resources={sample_period_seconds:5,samples:resources.length,processes:Object.fromEntries(processNames.map(name=>{const values=resources.flatMap(r=>r.processes.filter(p=>p.name===name));return[name,{rss_peak_bytes:Math.max(...values.map(v=>v.rss_bytes)),cpu_peak_percent_one_core:Math.max(...values.map(v=>v.cpu_percent_one_core)),cpu_total_ms_last_sample:values.at(-1)?.cpu_total_ms}];})),postgres_shared_cluster_rss_peak_bytes:resources.length?Math.max(...resources.map(r=>r.postgres_shared_cluster_rss_bytes)):null,postgres_note:'Shared PostgreSQL server includes other databases/tests; not isolated CPU/RSS.',queue_peak_jobs:databaseSamples.length?Math.max(...databaseSamples.map(r=>r.queued_jobs)):null,db_peak_bytes:databaseSamples.length?Math.max(...databaseSamples.map(r=>r.db_bytes)):null,disk_min_free_bytes:databaseSamples.length?Math.min(...databaseSamples.map(r=>r.disk_free_bytes)):null};
report.isolated_database=fixture.database;
report.phase_timing={capture_wall_seconds:wallSeconds,archive_wait_wall_seconds:archiveWaitSeconds,archive_harness_deadline_seconds:1800};
writeFileSync(reportPath,JSON.stringify(report,null,2));writeFileSync(runPath+'/progress.json',JSON.stringify({status:report.status,frames_sent:framesSent,acks:ackCount,report_path:reportPath}));console.log(JSON.stringify({status:report.status,duration_seconds:wallSeconds,viewers:viewerCount,frames:framesSent,report_path:reportPath,real_model:false,real_oss:false}));process.exitCode=failures.length?1:0;
