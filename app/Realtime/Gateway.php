<?php
namespace App\Realtime;
use App\Domain\{Access,Failure,AudioStore};
use App\Translation\{QwenConfig,QwenSession};
use App\Jobs\ArchiveLesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\TcpConnection;

final class Gateway {
 private array $clients=[];
 private array $upstreams=[];
 private bool $emergency=false;
 private int $backendPid=0;
 public function run(): void {
  $instance=substr(hash('sha256',config('classroom.ws_listen').config('database.connections.pgsql.database')),0,12);
  Worker::$pidFile=storage_path('framework/workerman-'.$instance.'.pid');
  Worker::$statusFile=storage_path('framework/workerman-'.$instance.'.status');
  Worker::$logFile=storage_path('logs/workerman-'.$instance.'.log');
  $worker=new Worker(config('classroom.ws_listen')); $worker->count=1; $worker->name='classroom-audio';
  $worker->onWorkerStart=function(){
   $lock=DB::selectOne('SELECT pg_try_advisory_lock(12884663) AS acquired, pg_backend_pid() AS pid');
   if(!$lock->acquired)throw new \RuntimeException('A classroom gateway already holds the database lock');
   $this->backendPid=(int)$lock->pid;
   // A process restart must not resume capture or billing without a human action.
   DB::transaction(function(){ foreach(DB::table('lessons')->whereIn('translation_status',['connecting','ready','finishing'])->orWhere('status','live')->lockForUpdate()->get() as $l){DB::table('lessons')->where('id',$l->id)->update(['status'=>$l->status==='ended'?'ended':'paused','translation_status'=>'interrupted','recorder_connection'=>null,'lease_until'=>null]);Access::lessonEvent((int)$l->id);} });
   Timer::add(0.1,fn()=>$this->pump()); Timer::add(2,fn()=>$this->watchdog());
  };
  $worker->onConnect=function(TcpConnection $c){$c->maxSendBufferSize=1048576;$c->maxPackageSize=8192;$c->context->key=bin2hex(random_bytes(16));$c->context->created=microtime(true);$c->context->originOk=false;$c->context->authorized=false;Timer::add(10,function()use($c){if(!isset($this->clients[$c->context->key]))$c->close();},[],false);};
  $worker->onWebSocketConnect=function(TcpConnection $c,$request){$origin=$request->header('origin'); if(!in_array($origin,config('classroom.allowed_origins'),true)){ $c->close();return;} $c->context->originOk=true;};
  $worker->onMessage=function(TcpConnection $c,string $data){
   try{ if(!$c->context->originOk)throw new Failure('origin_rejected',403);
    if(strlen($data)>8192)throw new Failure('message_too_large',413);
    if(str_starts_with($data,'{')){$msg=json_decode($data,true,16,JSON_THROW_ON_ERROR);$this->command($c,$msg);} else {$this->audio($c,$data);}
   } catch(Failure $e){if(str_starts_with($data,'{')&&($msg['type']??'')==='subscribe'&&!$c->context->authorized)unset($this->clients[$c->context->key]);$this->send($c,['type'=>'error','code'=>$e->errorCode,'params'=>$e->params]);if(in_array($e->errorCode,['disk_capacity_limit','storage_unavailable','duration_limit','audio_sequence_gap','audio_rate_limited']))$this->pauseConnection($c,$e->errorCode);}
   catch(\Throwable $e){$this->send($c,['type'=>'error','code'=>'gateway_failure']);$this->pauseConnection($c,'gateway_failure');logger()->error('Gateway operation failed',['class'=>get_class($e)]);}
  };
  $worker->onBufferFull=fn(TcpConnection $c)=>$c->close();
  $worker->onClose=function(TcpConnection $c){$this->pauseConnection($c,'recorder_disconnected');unset($this->clients[$c->context->key]);};
  $worker->onWorkerStop=function(){if(!$this->emergency)$this->guard(function(){foreach(array_keys($this->upstreams)as$id)$this->stop((int)$id,false,'gateway_shutdown');});};
  Worker::runAll();
 }
 private function send(TcpConnection $c,array $data):void {$c->send(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));}
 private function actor(TcpConnection $c):?object {
  $this->assertSingleton();
  $client=$this->clients[$c->context->key]??null;if(!$client)throw new Failure('subscribe_required',401);
  $s=DB::table('sessions')->where('id',$client['session_id'])->first(); if(!$s || $s->last_activity<time()-config('session.lifetime')*60)throw new Failure('session_revoked',401);
  $user=Access::user($client['user_id']);if($client['user_id']&&!$user)throw new Failure('session_revoked',401); return $user;
 }
 private function command(TcpConnection $c,array $msg):void {
  $type=$msg['type']??'';$key=$c->context->key;
  if($type==='subscribe'){
   if(isset($this->clients[$key]))throw new Failure('already_subscribed');
   $ticket=DB::transaction(function()use($msg){$t=DB::table('ws_tickets')->where('hash',hash('sha256',(string)($msg['ticket']??'')))->lockForUpdate()->first();if(!$t||$t->consumed_at||strtotime($t->expires_at)<time())throw new Failure('invalid_ticket',401);DB::table('ws_tickets')->where('hash',$t->hash)->update(['consumed_at'=>now()]);return $t;});
   $this->clients[$key]=['connection'=>$c,'lesson_id'=>(int)$ticket->lesson_id,'user_id'=>$ticket->user_id===null?null:(int)$ticket->user_id,'session_id'=>$ticket->session_id,'heartbeat'=>time(),'command_window'=>microtime(true),'commands'=>0];
   $u=$this->actor($c);
   $snapshot=DB::transaction(function()use($ticket,$u){DB::table('lessons')->where('id',$ticket->lesson_id)->lockForUpdate()->first();$l=Access::lesson((int)$ticket->lesson_id,$u);$units=DB::table('subtitle_units')->where('lesson_id',$l->id)->orderByDesc('id')->limit(200)->get()->reverse()->values()->map(fn($a)=>Access::unit($a))->all();$last=(int)DB::table('lesson_events')->where('lesson_id',$l->id)->max('seq');return ['type'=>'snapshot','lesson'=>Access::publicLesson($l),'units'=>$units,'last_seq'=>$last];});
   $c->context->authorized=true;$this->send($c,$snapshot);return;
  }
  $u=$this->actor($c);$client=&$this->clients[$key];$id=$client['lesson_id'];$l=Access::lesson($id,$u);
  if(microtime(true)-$client['command_window']>5){$client['commands']=0;$client['command_window']=microtime(true);}if(++$client['commands']>50)throw new Failure('rate_limited',429);
  if($type==='heartbeat'){$client['heartbeat']=time();if($l->recorder_connection===$key)DB::table('lessons')->where('id',$id)->update(['lease_until'=>now()->addSeconds(config('classroom.lease_seconds'))]);$this->send($c,['type'=>'heartbeat']);return;}
  Access::member($u);
  if($type==='start'||$type==='resume'){$this->start($c,$l,$u);return;}
  if($type==='pause') {if($l->recorder_connection!==$key||(int)$l->recorder_epoch!==(int)($msg['epoch']??-1))throw new Failure('recorder_stale',403);$this->stop($id,false,'user_pause');return;}
  if($type==='end') {if((int)$l->recorder_user_id!==(int)$u->id&&(int)$l->creator_id!==(int)$u->id&&$u->role!=='admin')throw new Failure('forbidden',403);if(($msg['confirmed']??false)!==true)throw new Failure('confirmation_required');$this->stop($id,true,'user_end');return;}
  throw new Failure('unknown_command');
 }
 private function start(TcpConnection $c,object $l,object $u):void {
  $id=(int)$l->id;$key=$c->context->key;if(isset($this->upstreams[$id]))throw new Failure('model_session_busy',409);
  $cfg=QwenConfig::fromLaravel();try{$cfg->validate();}catch(\Throwable){throw new Failure('model_configuration_missing',503,['missing'=>$cfg->missing()]);}
  try{new \App\Storage\OssConfiguration(config('classroom.oss'));}catch(\Throwable){throw new Failure('storage_configuration_missing',503);}
  $sid=(string)Str::uuid();
  $epoch=DB::transaction(function()use($id,$u,$key,$sid,$cfg){
   $row=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!in_array($row->status,['ready','paused']))throw new Failure('invalid_state',409);if((int)$row->recorder_user_id!==(int)$u->id)throw new Failure('not_recorder',403);
   if($row->recorder_connection&&$row->recorder_connection!==$key&&strtotime($row->lease_until)>time())throw new Failure('recorder_busy',409);
   if(count($this->upstreams)>=config('classroom.max_active'))throw new Failure('concurrency_limit',429);
   $epoch=(int)$row->recorder_epoch+1;DB::table('lessons')->where('id',$id)->update(['recorder_connection'=>$key,'recorder_epoch'=>$epoch,'next_sequence'=>0,'lease_until'=>now()->addSeconds(config('classroom.lease_seconds')),'translation_status'=>'connecting']);
   DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$id,'base_sample'=>$row->media_samples,'model'=>$cfg->model,'region'=>$cfg->region,'config'=>json_encode(['output_modalities'=>['text'],'translation'=>['language'=>$row->target_language]]),'status'=>'connecting']); Access::lessonEvent($id); return $epoch;
  });
  $base=(int)$l->media_samples;
  $peer=new QwenSession($cfg,$l->target_language,fn(array $event)=>$this->guard(fn()=>$this->modelEvent($id,$sid,$base,$event)),function(string $state,array $details=[])use($id,$sid,$c,$key,$epoch){$this->guard(function()use($state,$details,$id,$sid,$c,$key,$epoch){
   DB::transaction(function()use($id,$sid,$state){DB::table('model_sessions')->where('id',$sid)->update(['status'=>$state]);DB::table('lessons')->where('id',$id)->update(['translation_status'=>$state]); if($state==='ready'){DB::table('lessons')->where('id',$id)->where('status','<>','ended')->update(['status'=>'live','started_at'=>DB::raw('COALESCE(started_at,now())')]);}Access::lessonEvent($id);});
   if($state==='ready'){if(!isset($this->clients[$key])){$this->stop($id,false,'recorder_disconnected');return;}$c->context->audioStart=microtime(true);$c->context->receivedFrames=0;$this->send($c,['type'=>'recorder.ready','epoch'=>$epoch,'next_sequence'=>0,'media_samples'=>(int)DB::table('lessons')->where('id',$id)->value('media_samples')]);}
   if($state==='failed'){$this->send($c,['type'=>'error','code'=>'translation_interrupted']);$this->stop($id,false,'translation_interrupted');}
   if(in_array($state,['finished','closed','failed'])){$this->finalize($id,$sid);}
  });});
  $this->upstreams[$id]=['peer'=>$peer,'sid'=>$sid];$peer->connect();
 }
 private function audio(TcpConnection $c,string $frame):void {
  if(strlen($frame)<10||strlen($frame)>3208||strlen($frame)%2)throw new Failure('invalid_audio_frame');$u=$this->actor($c);Access::member($u);$id=$this->clients[$c->context->key]['lesson_id'];
  Access::lesson($id,$u);if(!isset($this->upstreams[$id])||!$this->upstreams[$id]['peer']->isReady())throw new Failure('translation_not_ready',409);
  $head=unpack('Nepoch/Nsequence',substr($frame,0,8));
  if(++$c->context->receivedFrames>(microtime(true)-$c->context->audioStart)*10+10)throw new Failure('audio_rate_limited',429);
  $ack=(new AudioStore)->accept($id,(int)$u->id,$c->context->key,$head['epoch'],$head['sequence'],substr($frame,8));
  $this->send($c,['type'=>'audio.ack','sequence'=>$ack['sequence'],'media_samples'=>$ack['media_samples']]);
  if(!$ack['duplicate']){try{$this->upstreams[$id]['peer']->sendAudio(substr($frame,8));DB::table('audio_segments')->where('id',$ack['segment_id'])->update(['model_delivery'=>'sent']);}catch(\Throwable){$this->stop($id,false,'translation_interrupted');$this->send($c,['type'=>'error','code'=>'translation_interrupted']);}}
 }
 private function stop(int $id,bool $end,string $reason):void {
  DB::transaction(function()use($id,$end,$reason){$l=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!$l||$l->status==='ended')return;DB::table('lessons')->where('id',$id)->update(['status'=>$end?'ended':'paused','ended_at'=>$end?now():null,'recorder_connection'=>null,'lease_until'=>null,'translation_status'=>isset($this->upstreams[$id])?'finishing':'idle','recorder_user_id'=>$end?null:$l->recorder_user_id]);DB::table('audit')->insert(['lesson_id'=>$id,'action'=>$reason]);Access::lessonEvent($id);});
  if(isset($this->upstreams[$id]))$this->upstreams[$id]['peer']->finish();else if($end)ArchiveLesson::dispatch($id);
 }
 private function finalize(int $id,string $sid):void {
  if(!isset($this->upstreams[$id])||$this->upstreams[$id]['sid']!==$sid)return;
  DB::transaction(function()use($id,$sid){DB::table('model_sessions')->where('id',$sid)->update(['ended_at'=>now()]);$units=DB::table('subtitle_units')->where('model_session_id',$sid)->whereIn('status',['streaming','text_done'])->get();foreach($units as $unit){DB::table('subtitle_units')->where('id',$unit->id)->update(['status'=>'interrupted']);$unit->status='interrupted';Access::event($id,'subtitle.updated',['unit'=>Access::unit($unit)]);}});
  DB::transaction(function()use($id,$sid){$state=DB::table('model_sessions')->where('id',$sid)->value('status');DB::table('lessons')->where('id',$id)->update(['translation_status'=>$state]);Access::lessonEvent($id);});
  if(DB::table('lessons')->where('id',$id)->value('status')==='ended')ArchiveLesson::dispatch($id);
  unset($this->upstreams[$id]);
 }
 private function modelEvent(int $id,string $sid,int $base,array $event):void {
  if(($event['type']??'')==='usage'){DB::table('usage_records')->insert(['lesson_id'=>$id,'model_session_id'=>$sid,'response_id'=>$event['response_id']??null,'status'=>$event['status']??null,'usage'=>json_encode($event['usage']??[])]);return;}
  if(($event['type']??'')!=='subtitle.upsert')return;
  DB::transaction(function()use($id,$sid,$base,$event){
   DB::table('lessons')->where('id',$id)->lockForUpdate()->first();
   $eid=$event['provider_event_id']??null;$unitId=$event['unit_id'];
   if($eid&&DB::table('provider_events')->insertOrIgnore(['session_id'=>$sid,'event_id'=>$eid.'|'.$unitId])===0)return;
   $old=DB::table('subtitle_units')->where(['model_session_id'=>$sid,'provider_unit_id'=>$unitId])->lockForUpdate()->first();
   if($old&&$old->status==='completed'&&($event['status']??'')!=='completed')return;
   $data=['lesson_id'=>$id,'model_session_id'=>$sid,'provider_unit_id'=>$unitId,'kind'=>$event['kind'],'item_id'=>$event['item_id']??null,'response_id'=>$event['response_id']??null,'source_item_id'=>$event['source_item_id']??null,'content_index'=>$event['content_index']??0,'original_text'=>$event['text']??'','status'=>$event['status'],'language'=>$event['language']??null,'start_sample'=>isset($event['start_ms'])?$base+$event['start_ms']*16:null,'end_sample'=>isset($event['end_ms'])?$base+$event['end_ms']*16:null,'timing_precision'=>$event['timing_precision']??'unavailable','mapping'=>$event['mapping']??'unavailable','updated_at'=>now()];
   if($old){if($old->status==='completed')$data['original_text']=$old->original_text;DB::table('subtitle_units')->where('id',$old->id)->update($data);$uid=$old->id;}else{$uid=DB::table('subtitle_units')->insertGetId($data);}
   $unit=Access::unit(DB::table('subtitle_units')->find($uid));Access::event($id,'subtitle.updated',['unit'=>$unit]);
  });
 }
 private function pauseConnection(TcpConnection $c,string $reason):void {if($this->emergency)return;try{$a=$this->clients[$c->context->key]??null;if($a&&DB::table('lessons')->where('id',$a['lesson_id'])->value('recorder_connection')===$c->context->key)$this->stop($a['lesson_id'],false,$reason);}catch(\Throwable){$c->close();}}
 private function pump():void {try{ $events=DB::table('lesson_events')->whereNull('broadcasted_at')->orderBy('seq')->limit(500)->get();foreach($events as $e){foreach($this->clients as $client)if($client['lesson_id']===(int)$e->lesson_id){try{Access::lesson($client['lesson_id'],$this->actor($client['connection']));$this->send($client['connection'],['type'=>'event','seq'=>(int)$e->seq,'event_type'=>$e->event_type,'payload'=>json_decode($e->payload,true)]);}catch(Failure){$client['connection']->close();}}DB::table('lesson_events')->where('seq',$e->seq)->update(['broadcasted_at'=>now()]);} }catch(\Throwable){foreach($this->clients as $a){$this->send($a['connection'],['type'=>'error','code'=>'database_unavailable']);$a['connection']->close();}foreach($this->upstreams as $up)$up['peer']->close();$this->upstreams=[];} }
 private function watchdog():void {foreach($this->clients as $a){$c=$a['connection'];try{$u=$this->actor($c);Access::lesson($a['lesson_id'],$u);if($a['heartbeat']<time()-config('classroom.lease_seconds'))$c->close();}catch(\Throwable){$c->close();}}}
 private function guard(callable $work):void {if($this->emergency)return;try{$work();}catch(\Throwable){$this->emergencyStop();}}
 private function emergencyStop():void {if($this->emergency)return;$this->emergency=true;foreach($this->clients as $client){$this->send($client['connection'],['type'=>'error','code'=>'database_unavailable']);$client['connection']->close();}foreach($this->upstreams as $up)$up['peer']->close();$this->upstreams=[];logger()->error('Gateway stopped after persistence or singleton-lock failure; supervisor restart required');Worker::stopAll();}
 private function assertSingleton():void {if($this->backendPid&&(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid!==$this->backendPid){$this->emergencyStop();throw new Failure('gateway_lock_lost',503);}}
}
