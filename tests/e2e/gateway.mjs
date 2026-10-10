// Actual PHP HTTP + Workerman + PostgreSQL; the upstream provider is an explicit fixture.
import {createRequire} from 'node:module';
import {readFileSync,writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const require=createRequire(new URL('../../frontend/package.json',import.meta.url));
const {chromium}=require('@playwright/test');
const fixture=JSON.parse(readFileSync(process.argv[2],'utf8'));
const browser=await chromium.launch({headless:true,channel:process.env.BROWSER_CHANNEL||'msedge'});
const contexts=[];
try {
 for(let i=0;i<3;i++)contexts.push(await browser.newContext());
 const pages=[];
 for(const ctx of contexts){const page=await ctx.newPage();await page.goto('http://localhost:18080/api/me');pages.push(page);}
 let recorderCsrf=(await contexts[0].request.get('http://localhost:18080/api/me')).json();recorderCsrf=await recorderCsrf;
 const login=await contexts[0].request.post('http://localhost:18080/api/login',{headers:{'X-CSRF-TOKEN':recorderCsrf.csrf_token,'Origin':'http://localhost:18080','Accept':'application/json'},data:{student_id:fixture.student_id,password:'Fixture-password-2026'}});
 assert.equal(login.status(),200,await login.text());
 for(let i=0;i<3;i++){
  const me=await(await contexts[i].request.get('http://localhost:18080/api/me')).json();
  const t=await contexts[i].request.post(`http://localhost:18080/api/lessons/${fixture.lesson_id}/ticket`,{headers:{'X-CSRF-TOKEN':me.csrf_token,'Origin':'http://localhost:18080','Accept':'application/json'},data:{}});
  assert.equal(t.status(),200,await t.text());const {ticket}=await t.json();
  await pages[i].evaluate(({ticket})=>{window.events=[];window.sock=new WebSocket('ws://localhost:18081');window.sock.onmessage=e=>window.events.push(JSON.parse(e.data));window.sock.onopen=()=>window.sock.send(JSON.stringify({type:'subscribe',ticket,last_seq:0}));}, {ticket});
  await pages[i].waitForFunction(()=>window.events.some(x=>x.type==='snapshot'));
 }
 // Guests cannot start and cannot upload audio, even through handcrafted messages.
 await pages[1].evaluate(()=>window.sock.send(JSON.stringify({type:'start'})));
 await pages[1].waitForFunction(()=>window.events.some(x=>x.type==='error'&&x.code==='login_required'));
 await pages[0].evaluate(()=>window.sock.send(JSON.stringify({type:'start'})));
 await pages[0].waitForFunction(()=>window.events.some(x=>x.type==='recorder.ready'));
 await pages[0].evaluate(()=>window.sock.send(JSON.stringify({type:'subscribe',ticket:'invalid-repeat'})));
 await pages[0].waitForFunction(()=>window.events.some(x=>x.type==='error'&&x.code==='already_subscribed'));
 const ready=await pages[0].evaluate(()=>window.events.find(x=>x.type==='recorder.ready'));
 for(let seq=0;seq<6;seq++){
  await pages[0].evaluate(({epoch,seq})=>{const b=new ArrayBuffer(3208);const v=new DataView(b);v.setUint32(0,epoch);v.setUint32(4,seq);for(let n=0;n<1600;n++)v.setInt16(8+n*2,Math.round(5000*Math.sin(n*2*Math.PI*440/16000)),true);window.sock.send(b);},{epoch:ready.epoch,seq});
  await pages[0].waitForTimeout(100);
 }
 await pages[0].waitForFunction(()=>window.events.filter(x=>x.type==='audio.ack').length===6);
 await pages[0].evaluate(({epoch})=>{const b=new ArrayBuffer(8+1024);const v=new DataView(b);v.setUint32(0,epoch);v.setUint32(4,6);for(let n=0;n<512;n++)v.setInt16(8+n*2,1000,true);window.sock.send(b);},ready);
 await pages[0].waitForFunction(()=>window.events.filter(x=>x.type==='audio.ack').length===7);
 await pages[0].evaluate(({epoch})=>window.sock.send(JSON.stringify({type:'end',epoch,confirmed:true})),ready);
 for(const page of pages)await page.waitForFunction(()=>window.events.some(x=>x.type==='event'&&x.event_type==='subtitle.updated'&&x.payload.unit.kind==='translation'&&x.payload.unit.status==='completed'));
 const results=[];for(const page of pages)results.push(await page.evaluate(()=>window.events.filter(x=>x.type==='event'&&x.event_type==='subtitle.updated'&&x.payload.unit.status==='completed').map(x=>x.payload.unit)));
 for(const r of results){assert.ok(r.some(x=>x.kind==='source'&&x.text==='Lift coefficient.'));assert.ok(r.some(x=>x.kind==='translation'&&x.text==='升力系数。'));}
 const readback=await(await contexts[2].request.get(`http://localhost:18080/api/lessons/${fixture.lesson_id}`)).json();
 assert.equal(readback.lesson.status,'ended');assert.equal(readback.lesson.media_samples,10112);assert.equal(readback.units.length,2);assert.equal(readback.units.filter(x=>x.status==='completed').length,2);
 const report={classification:'SIMULATED_QWEN_REAL_PHP_POSTGRESQL',status:'passed',clients:3,pcm_frames:7,tail_samples:512,media_samples:10112,completed_source:1,completed_translation:1,duplicate_response_done_deduplicated:true,guest_start_denied:true,real_model:false,real_oss:false,real_microphone:false};
 writeFileSync(new URL('../../reports/gateway-simulated.json',import.meta.url),JSON.stringify(report,null,2));console.log(JSON.stringify(report));
}finally{for(const ctx of contexts)await ctx.close();await browser.close();}
