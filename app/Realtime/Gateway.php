<?php
namespace App\Realtime;
use App\Domain\{Access,Failure,AudioStore,RecorderHandoffs};
use App\Translation\{QwenConfig,QwenSession};
use App\Jobs\ArchiveLesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\TcpConnection;

final class Gateway {
 private const RECONNECT_SECONDS=25;
 private array $clients=[];
 private array $recorderReconnect=[];
 private array $recovering=[];
 private array $upstreams=[];
 private array $preparing=[];
 private array $draining=[];
 private array $policies=[];
 private array $pendingStarts=[];
 private bool $emergency=false;
 private int $backendPid=0;
 private bool $captionPumpPending=false;
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
   $this->policies=\App\Settings\Settings::policies();\App\Reliability\Recovery::restart();
   Timer::add(0.1,fn()=>$this->pump()); Timer::add(2,fn()=>$this->guard(fn()=>$this->watchdog())); Timer::add(1,fn()=>$this->guard(fn()=>$this->presence()));
  };
  $worker->onConnect=function(TcpConnection $c){$c->maxSendBufferSize=1048576;$c->maxPackageSize=8192;$c->context->key=bin2hex(random_bytes(16));$c->context->created=microtime(true);$c->context->originOk=false;$c->context->authorized=false;Timer::add(10,function()use($c){if(!isset($this->clients[$c->context->key]))$c->close();},[],false);};
  $worker->onWebSocketConnect=function(TcpConnection $c,$request){$origin=$request->header('origin'); if(!in_array($origin,config('classroom.allowed_origins'),true)){ $c->close();return;} $c->context->originOk=true;};
  $worker->onMessage=function(TcpConnection $c,string $data){
   try{ if(!$c->context->originOk)throw new Failure('origin_rejected',403);
    if(strlen($data)>8192)throw new Failure('message_too_large',413);
    if(str_starts_with($data,'{')){$msg=json_decode($data,true,16,JSON_THROW_ON_ERROR);$this->command($c,$msg);} else {$this->audio($c,$data);}
   } catch(Failure $e){if(str_starts_with($data,'{')&&($msg['type']??'')==='subscribe'&&!$c->context->authorized)unset($this->clients[$c->context->key]);$this->send($c,['type'=>'error','code'=>$e->errorCode,'params'=>$e->params]);if(in_array($e->errorCode,['disk_capacity_limit','storage_unavailable','duration_limit','daily_audio_limit','audio_sequence_gap','audio_rate_limited']))$this->pauseConnection($c,$e->errorCode);}
   catch(\Throwable $e){$this->send($c,['type'=>'error','code'=>'gateway_failure']);$this->pauseConnection($c,'gateway_failure');logger()->error('Gateway operation failed',['class'=>get_class($e)]);}
  };
  $worker->onBufferFull=fn(TcpConnection $c)=>$c->close();
  $worker->onClose=fn(TcpConnection $c)=>$this->guard(fn()=>$this->disconnect($c));
  $worker->onWorkerStop=function(){if(!$this->emergency)$this->guard(function(){foreach(array_keys($this->upstreams+$this->recovering)as$id)$this->stop((int)$id,false,'gateway_shutdown');});};
  Worker::runAll();
 }
 private function send(TcpConnection $c,array $data):void {$c->send(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));}
 private function actor(TcpConnection $c):?object {
  $this->assertSingleton();
  $client=$this->clients[$c->context->key]??null;if(!$client)throw new Failure('subscribe_required',401);
  $s=DB::table('sessions')->where('id',$client['session_id'])->first(); if(!$s || $s->last_activity<time()-config('session.lifetime')*60)throw new Failure('session_revoked',401);
  $user=Access::user($client['user_id']);if($client['user_id']&&(!$user||(int)$s->user_id!==(int)$user->id||(int)$s->auth_version!==(int)$user->auth_version))throw new Failure('session_revoked',401); return $user;
 }
 private function command(TcpConnection $c,array $msg):void {
  $type=$msg['type']??'';$key=$c->context->key;
  if($type==='subscribe'){
   if(isset($this->clients[$key]))throw new Failure('already_subscribed');
   $ticket=DB::transaction(function()use($msg){$t=DB::table('ws_tickets')->where('hash',hash('sha256',(string)($msg['ticket']??'')))->lockForUpdate()->first();if(!$t||$t->consumed_at||strtotime($t->expires_at)<time())throw new Failure('invalid_ticket',401);DB::table('ws_tickets')->where('hash',$t->hash)->update(['consumed_at'=>now()]);return $t;});
   $this->clients[$key]=['connection'=>$c,'lesson_id'=>(int)$ticket->lesson_id,'user_id'=>$ticket->user_id===null?null:(int)$ticket->user_id,'session_id'=>$ticket->session_id,'heartbeat'=>time(),'command_window'=>microtime(true),'commands'=>0,'reconnect'=>($msg['recorder_reconnect']??false)===true];
   $u=$this->actor($c);
   $resume=$this->resumeRecorder($c,$u,$msg['recorder_token']??null);
   $snapshot=DB::transaction(function()use($ticket,$u){DB::table('lessons')->where('id',$ticket->lesson_id)->lockForUpdate()->first();$l=Access::lesson((int)$ticket->lesson_id,$u,$ticket->session_id);$units=\App\Content\SubtitleText::visible(DB::table('subtitle_units')->where('lesson_id',$l->id)->whereNull('deleted_at'))->orderByDesc('id')->limit(200)->get()->reverse()->values()->map(fn($a)=>Access::unit($a))->all();$last=(int)DB::table('lesson_events')->where('lesson_id',$l->id)->max('seq');return ['type'=>'snapshot','lesson'=>Access::publicLesson($l),'units'=>$units,'last_seq'=>$last];});
   if($resume)$snapshot['recorder_resume']=$resume;
   $recovery=\App\Reliability\Recovery::replay((int)$ticket->lesson_id,max(0,(int)($msg['last_seq']??0)),$snapshot['last_seq']);$snapshot['missed_events']=$recovery['missed_events'];unset($recovery['missed_events']);$snapshot['recovery']=$recovery;
   $c->context->authorized=true;$this->send($c,$snapshot);return;
  }
  $u=$this->actor($c);$client=&$this->clients[$key];$id=$client['lesson_id'];$l=Access::lesson($id,$u,$client['session_id']);
  if(microtime(true)-$client['command_window']>5){$client['commands']=0;$client['command_window']=microtime(true);}if(++$client['commands']>50)throw new Failure('rate_limited',429);
  if($type==='heartbeat'){$client['heartbeat']=time();if($l->recorder_connection===$key)DB::table('lessons')->where('id',$id)->where('recorder_connection',$key)->where('recorder_epoch',$l->recorder_epoch)->update(['lease_until'=>now()->addSeconds(config('classroom.lease_seconds'))]);$this->send($c,['type'=>'heartbeat']);return;}
  Access::member($u);
  if(str_starts_with($type,'recording.')){$this->handoffCommand($c,$l,$u,$msg);return;}
  if($type==='start'||$type==='resume'){$this->start($c,$l,$u);return;}
  if($type==='pause') {if($l->recorder_connection!==$key||(int)$l->recorder_epoch!==(int)($msg['epoch']??-1))throw new Failure('recorder_stale',403);$this->stop($id,false,'user_pause');return;}
  if($type==='end') {if((int)$l->recorder_user_id!==(int)$u->id&&(int)$l->creator_id!==(int)$u->id&&$u->role!=='admin')throw new Failure('forbidden',403);if(($msg['confirmed']??false)!==true)throw new Failure('confirmation_required');$this->stop($id,true,'user_end');return;}
  throw new Failure('unknown_command');
 }
 private function disconnect(TcpConnection $c):void {
  $key=$c->context->key;$client=$this->clients[$key]??null;
  if($client){
   $id=$client['lesson_id'];$resume=$this->recorderReconnect[$id]??null;
   // A transport loss is different from an explicit pause, handoff or revoked login.
   if($resume&&$resume['key']===$key&&$c->context->authorized){
    $held=DB::table('lessons')->where('id',$id)->where('status','live')->whereNull('deleted_at')->whereNull('purge_status')->where('recorder_connection',$key)->where('recorder_epoch',$resume['epoch']);
    if($held->update(['lease_until'=>now()->addSeconds(self::RECONNECT_SECONDS)])){
     $this->recorderReconnect[$id]=[...$resume,'until'=>microtime(true)+self::RECONNECT_SECONDS,'audio_start'=>$c->context->audioStart,'received_frames'=>$c->context->receivedFrames];
    }else $this->pauseConnection($c,'recorder_disconnected');
   }else $this->pauseConnection($c,'recorder_disconnected');
   unset($this->clients[$key]);
  }
  (new RecorderHandoffs)->cancelConnection($key);
 }

 private function resumeRecorder(TcpConnection $c,?object $user,mixed $token):?array {
  if(!is_string($token)||strlen($token)!==64||!$user||$user->must_change_password)return null;
  $key=$c->context->key;$client=$this->clients[$key];$id=$client['lesson_id'];$resume=$this->recorderReconnect[$id]??null;
  if(!$client['reconnect']||!$resume||$resume['session']!==$client['session_id']||!hash_equals($resume['token'],$token))return null;
  if($resume['until']!==null&&microtime(true)>=$resume['until'])return null;
  Access::lesson($id,$user,$client['session_id']);
  $lesson=DB::transaction(function()use($id,$resume,$user,$key){
   $row=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();
   if(!$row||$row->deleted_at||$row->purge_status!==null||$row->status!=='live'||(int)$row->recorder_user_id!==(int)$user->id||$row->recorder_connection!==$resume['key']||(int)$row->recorder_epoch!==$resume['epoch'])return null;
   DB::table('lessons')->where('id',$id)->update(['recorder_connection'=>$key,'lease_until'=>now()->addSeconds(config('classroom.lease_seconds')),'recorder_offline_at'=>null]);
   return $row;
  });
  if(!$lesson)return null;
  $old=$this->clients[$resume['key']]['connection']??null;
  $c->context->audioStart=$old?->context->audioStart??$resume['audio_start'];
  $c->context->receivedFrames=$old?->context->receivedFrames??$resume['received_frames'];
  $this->recorderReconnect[$id]=[...$resume,'key'=>$key,'until'=>null];
  if(isset($this->upstreams[$id]))$this->upstreams[$id]['context']->key=$key;
  if(isset($this->preparing[$id]))$this->preparing[$id]['context']->key=$key;
  $old?->close();
  return ['epoch'=>$resume['epoch'],'next_sequence'=>(int)$lesson->next_sequence];
 }
 private function start(TcpConnection $c,object $l,object $u):void {
  $id=(int)$l->id;$key=$c->context->key;if(isset($this->upstreams[$id])||isset($this->preparing[$id]))throw new Failure('model_session_busy',409);
  $policy=$this->policies?:\App\Settings\Settings::policies();$sid=(string)Str::uuid();
  $prepared=DB::transaction(function()use($id,$u,$key,$sid,$policy){
   // Settings save and first capture share the same lock order: settings before
   // lesson, actor and storage-version rows. Avoid a user/advisory-lock cycle.
   DB::select('SELECT pg_advisory_xact_lock(1702008)');
   $row=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();$u=\App\Content\MutationActor::lock($u);
   if(!$row||$row->deleted_at||$row->purge_status!==null)throw new Failure('lesson_unavailable',404);
   if(!in_array($row->status,['ready','paused']))throw new Failure('invalid_state',409);if((int)$row->recorder_user_id!==(int)$u->id)throw new Failure('not_recorder',403);
   if($row->recorder_connection&&$row->recorder_connection!==$key&&strtotime($row->lease_until)>time())throw new Failure('recorder_busy',409);
   if(count($this->upstreams+$this->recovering)>=$policy['max_active_lessons'])throw new Failure('concurrency_limit',429);
   \App\Reliability\Usage::assertStart($id,$policy);
   try{$oss=\App\Settings\Settings::values('oss');$current=\App\Storage\StorageRegistry::register($oss);$storageVersion=(int)$row->media_samples===0&&!$row->storage_version?$current:\App\Storage\StorageRegistry::lessonVersion($row);}catch(\Throwable){throw new Failure('storage_configuration_version_unavailable',503);}
   try{$values=\App\Settings\Settings::freezeModel($id);$terms=\App\Glossary\Glossary::snapshot($id,(int)$row->course_id,$row->target_language);$cfg=QwenConfig::fromArray([...$values,'phrases'=>$terms['phrases']],app()->environment('testing'));$cfg->validate();$update=$cfg->sessionUpdate($row->target_language);}catch(Failure $e){throw $e;}catch(\Throwable){throw new Failure('model_configuration_missing',503);}
   $epoch=(int)$row->recorder_epoch+1;DB::table('lessons')->where('id',$id)->update(['storage_version'=>$storageVersion,'recorder_connection'=>$key,'recorder_epoch'=>$epoch,'next_sequence'=>0,'lease_until'=>now()->addSeconds(config('classroom.lease_seconds')),'translation_status'=>'connecting','recorder_offline_at'=>null]);(new RecorderHandoffs)->invalidate($id);
   $meta=['output_modalities'=>['text'],'translation'=>['language'=>$row->target_language],'epoch'=>$epoch,'purpose'=>$row->started_at?'resume':'initial','glossary_version'=>$terms['version'],'glossary_hash'=>$terms['hash'],'glossary_count'=>$terms['count']];
   DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$id,'recorder_user_id'=>$u->id,'base_sample'=>$row->media_samples,'model'=>$cfg->model,'region'=>$cfg->region,'config'=>json_encode($meta),'status'=>'connecting']);Access::lessonEvent($id);return ['epoch'=>$epoch,'base'=>(int)$row->media_samples,'cfg'=>$cfg,'language'=>$row->target_language,'meta'=>$meta];
  });
  $context=(object)['base'=>$prepared['base'],'epoch'=>$prepared['epoch'],'key'=>$key];
  $peer=$this->newPeer($id,$sid,$prepared['cfg'],$prepared['language'],$context);
  $this->upstreams[$id]=['peer'=>$peer,'sid'=>$sid,'cfg'=>$prepared['cfg'],'language'=>$prepared['language'],'context'=>$context,'meta'=>$prepared['meta'],'next_rotation'=>0];$peer->connect();
 }
 private function newPeer(int $id,string $sid,QwenConfig $cfg,string $language,object $context):QwenSession {
  return new QwenSession($cfg,$language,fn(array $event)=>$this->guard(fn()=>$this->modelEvent($id,$sid,$context->base,$event)),fn(string $state,array $details=[])=>$this->guard(fn()=>$this->modelState($id,$sid,$state,$context)));
 }
 private function modelState(int $id,string $sid,string $state,object $context):void {
   $active=($this->upstreams[$id]['sid']??null)===$sid;$preparing=($this->preparing[$id]['sid']??null)===$sid;
   $terminal=in_array($state,['finished','closed','failed'],true);$live=false;$recovering=$context->recovering??false;
   $writable=DB::transaction(function()use($id,$sid,$state,$active,$preparing,$terminal,$context,$recovering,&$live){$lesson=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();DB::table('model_sessions')->where('id',$sid)->update(['status'=>$state]);
    // Purge is irreversible for content, including callbacks already waiting in the event loop.
    if(!$lesson||$lesson->purge_status!==null)return false;
    $live=$lesson->status==='live';
    if($active&&!$terminal&&(!$recovering||$state==='ready')){
     if($recovering&&(!$live||$lesson->recorder_connection!==$context->key||(int)$lesson->recorder_epoch!==$context->epoch))return false;
     if($recovering){$context->base=(int)$lesson->media_samples;DB::table('model_sessions')->where('id',$sid)->update(['base_sample'=>$context->base]);}
     DB::table('lessons')->where('id',$id)->update(['translation_status'=>$state]);if($state==='ready')DB::table('lessons')->where('id',$id)->where('status','<>','ended')->update(['status'=>'live','started_at'=>DB::raw('COALESCE(started_at,now())')]);Access::lessonEvent($id);
    }
    if($state==='failed'&&$preparing)Access::event($id,'translation.rotation',['phase'=>'failed','approximate_boundary'=>false]);return true;
   });
   if(!$writable){if($terminal)$this->finalize($id,$sid);elseif($active&&$state==='ready')$this->upstreams[$id]['peer']->close();return;}
   if($state==='ready'&&$preparing){$this->preparing[$id]['ready']=true;return;}
   if($state==='ready'&&$active){
    if($recovering){$context->attempt=0;return;}
    $key=$context->key;$client=$this->clients[$key]??null;if(!$client){$this->stop($id,false,'recorder_disconnected');return;}$c=$client['connection'];
    $c->context->audioStart=microtime(true);$c->context->receivedFrames=0;
    try{$l=Access::lesson($id,$this->actor($c),$client['session_id']);}catch(Failure){$this->stop($id,false,'session_revoked');$c->close();return;}
    $lessonSeq=(int)DB::table('lesson_events')->where('lesson_id',$id)->where('event_type','lesson.updated')->max('seq');
    $message=['type'=>'recorder.ready','epoch'=>$context->epoch,'next_sequence'=>0,'media_samples'=>(int)$l->media_samples,'lesson'=>Access::publicLesson($l),'lesson_seq'=>$lessonSeq];
    if($client['reconnect']){
     $message['recorder_token']=bin2hex(random_bytes(32));
     $this->recorderReconnect[$id]=['key'=>$key,'epoch'=>$context->epoch,'session'=>$client['session_id'],'token'=>$message['recorder_token'],'until'=>null,'audio_start'=>$c->context->audioStart,'received_frames'=>0];
    }
    $this->send($c,$message);
   }
   if($state==='failed'&&$active&&!$live){$key=$context->key;if(isset($this->clients[$key]))$this->send($this->clients[$key]['connection'],['type'=>'error','code'=>'translation_interrupted']);$this->stop($id,false,'translation_interrupted');}
   if($state==='failed'&&$preparing&&isset($this->upstreams[$id]))$this->upstreams[$id]['next_rotation']=microtime(true)+3;
   if($terminal)$this->finalize($id,$sid);
 }
 private function prepareRotation(int $id,bool $hard):void {
  if(isset($this->preparing[$id])||!isset($this->upstreams[$id]))return;$active=$this->upstreams[$id];if(!$active['peer']->isReady())return;
  $sid=(string)Str::uuid();$meta=[...$active['meta'],'purpose'=>'rotation','approximate_boundary'=>$hard];
  $l=DB::transaction(function()use($id,$sid,$active,$meta,$hard){$lesson=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!$lesson||$lesson->status!=='live'||$lesson->purge_status!==null)return null;
   DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$id,'recorder_user_id'=>$lesson->recorder_user_id,'base_sample'=>$lesson->media_samples,'model'=>$active['cfg']->model,'region'=>$active['cfg']->region,'config'=>json_encode($meta),'status'=>'connecting']);Access::event($id,'translation.rotation',['phase'=>'preparing','approximate_boundary'=>$hard]);return $lesson;
  });if(!$l)return;$context=(object)['base'=>(int)$l->media_samples,'epoch'=>(int)$l->recorder_epoch,'key'=>$l->recorder_connection];
  $peer=$this->newPeer($id,$sid,$active['cfg'],$active['language'],$context);$this->preparing[$id]=['sid'=>$sid,'peer'=>$peer,'context'=>$context,'ready'=>false,'hard'=>$hard,'meta'=>$meta];$peer->connect();
 }
 private function switchRotation(int $id,object $lesson):void {
  if(empty($this->preparing[$id]['ready'])||!isset($this->upstreams[$id]))return;$next=$this->preparing[$id];$old=$this->upstreams[$id];if(!$old['peer']->canRotateAtBoundary()){if($old['peer']->elapsedSeconds()<$this->policies['rotation_hard_seconds'])return;$next['hard']=true;$next['meta']['approximate_boundary']=true;}$next['context']->base=(int)$lesson->media_samples;
  DB::transaction(function()use($id,$lesson,$next,$old){$locked=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!$locked||$locked->status!=='live'||$locked->purge_status!==null)throw new Failure('recorder_stale',403);DB::table('model_sessions')->where('id',$next['sid'])->update(['base_sample'=>$lesson->media_samples,'config'=>json_encode($next['meta'])]);DB::table('model_sessions')->where('id',$old['sid'])->update(['status'=>'finishing']);Access::event($id,'translation.rotation',['phase'=>'switched','base_sample'=>(int)$lesson->media_samples,'approximate_boundary'=>$next['hard']]);if($next['hard'])\App\Reliability\Recovery::interrupted($lesson,'approximate_rotation_boundary');});
  $old['context']->end=(int)$lesson->media_samples;$this->draining[$id][$old['sid']]=$old;$this->upstreams[$id]=['sid'=>$next['sid'],'peer'=>$next['peer'],'cfg'=>$old['cfg'],'language'=>$old['language'],'context'=>$next['context'],'meta'=>$next['meta'],'next_rotation'=>0];unset($this->preparing[$id]);$old['peer']->finish();
 }

 private function audio(TcpConnection $c,string $frame):void {
  if(strlen($frame)<10||strlen($frame)>3208||strlen($frame)%2)throw new Failure('invalid_audio_frame');$u=$this->actor($c);Access::member($u);$id=$this->clients[$c->context->key]['lesson_id'];
  $l=Access::lesson($id,$u,$this->clients[$c->context->key]['session_id']);
  $head=unpack('Nepoch/Nsequence',substr($frame,0,8));
  if($l->status!=='live'||(int)$l->recorder_epoch!==$head['epoch']||$l->recorder_connection!==$c->context->key||!isset($c->context->audioStart))throw new Failure('recorder_stale',403);
  if(++$c->context->receivedFrames>(microtime(true)-$c->context->audioStart)*10+10)throw new Failure('audio_rate_limited',429);
  $this->switchRotation($id,$l);
  $ack=(new AudioStore)->accept($id,(int)$u->id,$c->context->key,$head['epoch'],$head['sequence'],substr($frame,8),$this->policies);
  $this->clients[$c->context->key]['heartbeat']=time();
  $this->send($c,['type'=>'audio.ack','sequence'=>$ack['sequence'],'media_samples'=>$ack['media_samples']]);
  $peer=$this->upstreams[$id]['peer']??null;
  if($ack['duplicate']||!$peer?->isReady())return;
  try{$peer->sendAudio(substr($frame,8));}catch(\Throwable){$peer->close();return;}
  DB::table('audio_segments')->where('id',$ack['segment_id'])->update(['model_delivery'=>'sent']);
 }
 private function stop(int $id,bool $end,string $reason):void {
  unset($this->recorderReconnect[$id],$this->recovering[$id]);
  $writable=DB::transaction(function()use($id,$end,$reason){$l=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!$l||$l->purge_status!==null)return false;if($l->status==='ended')return true;DB::table('lessons')->where('id',$id)->update(['status'=>$end?'ended':'paused','ended_at'=>$end?now():null,'recorder_connection'=>null,'lease_until'=>null,'translation_status'=>isset($this->upstreams[$id])?'finishing':'idle','recorder_user_id'=>$end?null:$l->recorder_user_id,'recorder_offline_at'=>in_array($reason,['recorder_disconnected','session_revoked'])?now():$l->recorder_offline_at]);if($end){(new RecorderHandoffs)->invalidate($id);unset($this->pendingStarts[$id]);}DB::table('audit')->insert(['lesson_id'=>$id,'action'=>$reason]);Access::lessonEvent($id);return true;});
  if($writable)AudioStore::flushLesson($id);if(isset($this->preparing[$id]))$this->preparing[$id]['peer']->close();
  if(isset($this->upstreams[$id]))$this->upstreams[$id]['peer']->finish();else if($end&&$writable&&empty($this->draining[$id]))ArchiveLesson::dispatch($id);
 }
 private function finalize(int $id,string $sid):void {
  $active=($this->upstreams[$id]['sid']??null)===$sid;$preparing=($this->preparing[$id]['sid']??null)===$sid;$draining=isset($this->draining[$id][$sid]);if(!$active&&!$preparing&&!$draining)return;
  $entry=$active?$this->upstreams[$id]:($preparing?$this->preparing[$id]:$this->draining[$id][$sid]);
  $retry=false;
  $writable=DB::transaction(function()use($id,$sid,$active,$preparing,$entry,&$retry){
   $lesson=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();$state=DB::table('model_sessions')->where('id',$sid)->value('status');$meta=json_decode(DB::table('model_sessions')->where('id',$sid)->value('config'),true,flags:JSON_THROW_ON_ERROR);$meta['sent_samples']=$entry['peer']->sentSamplesCount();$meta['sent_samples_complete']=true;DB::table('model_sessions')->where('id',$sid)->update(['ended_at'=>now(),'config'=>json_encode($meta)]);
   if(!$lesson||$lesson->purge_status!==null)return false;
   if(!$preparing&&$entry['peer']->sentSamplesCount()>0&&in_array($state,['failed','closed']))\App\Reliability\Recovery::interrupted($lesson,'translation_uncertain',$sid,$entry['context']->end??(int)$lesson->media_samples);
   foreach(DB::table('subtitle_units')->where('model_session_id',$sid)->whereIn('status',['streaming','text_done'])->get()as$unit){DB::table('subtitle_units')->where('id',$unit->id)->update(['status'=>'interrupted']);$unit->status='interrupted';Access::event($id,'subtitle.updated',['unit'=>Access::unit($unit)]);}
   if($active){$retry=$lesson->status==='live'&&!$lesson->deleted_at&&(bool)$lesson->recorder_connection;if($retry)\App\Reliability\Recovery::interrupted($lesson,'provider_session_ended',$sid);DB::table('lessons')->where('id',$id)->update(['translation_status'=>$retry?'recovering':$state]);Access::lessonEvent($id);}return true;
  });
  if($active)unset($this->upstreams[$id]);if($preparing)unset($this->preparing[$id]);if($draining){unset($this->draining[$id][$sid]);if(empty($this->draining[$id]))unset($this->draining[$id]);}
  if(!$writable){unset($this->pendingStarts[$id]);return;}
  if($retry){
   $attempt=($entry['context']->attempt??0)+1;
   $this->recovering[$id]=['cfg'=>$entry['cfg'],'language'=>$entry['language'],'meta'=>$entry['meta'],'attempt'=>$attempt,'retry_at'=>microtime(true)+min(30,2**min($attempt-1,5))];
   if(isset($this->preparing[$id]))$this->preparing[$id]['peer']->close();
  }
  $ended=DB::table('lessons')->where('id',$id)->whereNull('purge_status')->where('status','ended')->exists();if($ended&&!isset($this->upstreams[$id])&&empty($this->draining[$id]))ArchiveLesson::dispatch($id);
  if($active&&isset($this->pendingStarts[$id])){$key=$this->pendingStarts[$id];unset($this->pendingStarts[$id]);if(isset($this->clients[$key])){$client=$this->clients[$key];try{$u=$this->actor($client['connection']);$l=Access::lesson($id,$u,$client['session_id']);if($l->status!=='ended'&&(int)$l->recorder_user_id===(int)$u->id)$this->start($client['connection'],$l,$u);}catch(Failure $e){$this->send($client['connection'],['type'=>'error','code'=>$e->errorCode]);}}}
 }

 private function recoverTranslation(int $id,array $recovery):void {
  $sid=(string)Str::uuid();$meta=[...$recovery['meta'],'purpose'=>'resume','upstream_reconnect'=>true];
  $lesson=DB::transaction(function()use($id,$sid,$recovery,$meta){
   $row=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();
   if(!$row||$row->status!=='live'||$row->deleted_at||$row->purge_status!==null){unset($this->recovering[$id]);return null;}
   if(!isset($this->clients[$row->recorder_connection]))return null;
   DB::table('model_sessions')->insert(['id'=>$sid,'lesson_id'=>$id,'recorder_user_id'=>$row->recorder_user_id,'base_sample'=>$row->media_samples,'model'=>$recovery['cfg']->model,'region'=>$recovery['cfg']->region,'config'=>json_encode($meta),'status'=>'connecting']);
   return $row;
  });
  if(!$lesson)return;
  $context=(object)['base'=>(int)$lesson->media_samples,'epoch'=>(int)$lesson->recorder_epoch,'key'=>$lesson->recorder_connection,'recovering'=>true,'attempt'=>$recovery['attempt']];
  $peer=$this->newPeer($id,$sid,$recovery['cfg'],$recovery['language'],$context);
  $this->upstreams[$id]=['sid'=>$sid,'peer'=>$peer,'cfg'=>$recovery['cfg'],'language'=>$recovery['language'],'context'=>$context,'meta'=>$meta,'next_rotation'=>0];
  unset($this->recovering[$id]);$peer->connect();
 }

 private function modelEvent(int $id,string $sid,int $base,array $event):void {
  if(($event['type']??'')==='usage'){\App\Reliability\Usage::recordEvent($id,$sid,$event);return;}
  if(($event['type']??'')!=='subtitle.upsert')return;
  $changed=DB::transaction(function()use($id,$sid,$base,$event){
   $lesson=DB::table('lessons')->where('id',$id)->lockForUpdate()->first();if(!$lesson||$lesson->purge_status!==null)return;
   $eid=$event['provider_event_id']??null;$unitId=$event['unit_id'];
   if($eid&&DB::table('provider_events')->insertOrIgnore(['session_id'=>$sid,'event_id'=>$eid.'|'.$unitId])===0)return;
   $old=DB::table('subtitle_units')->where(['model_session_id'=>$sid,'provider_unit_id'=>$unitId])->lockForUpdate()->first();
   if($old&&$old->status==='completed'&&($event['status']??'')!=='completed')return;
   if(!\App\Content\SubtitleText::hasContent($event['text']??'')){
    // Empty provider placeholders never become subtitle records. If a final result
    // retracts an unfinished draft, remove it and notify every client by tombstone.
    if(!$old)return;
    if($old->status!=='completed'){
     if(($event['status']??'streaming')!=='streaming'){$old->deleted_at=now();Access::event($id,'subtitle.updated',['unit'=>Access::unit($old)]);DB::table('subtitle_units')->where('id',$old->id)->delete();return true;}
     return;
    }
    // Late timing metadata must not erase a confirmed result or manual correction.
    if(!\App\Content\SubtitleText::hasContent($old->current_text??$old->original_text))return;
   }
   $data=['lesson_id'=>$id,'model_session_id'=>$sid,'provider_unit_id'=>$unitId,'kind'=>$event['kind'],'item_id'=>$event['item_id']??null,'response_id'=>$event['response_id']??null,'source_item_id'=>$event['source_item_id']??null,'content_index'=>$event['content_index']??0,'original_text'=>$event['text']??'','status'=>$event['status'],'language'=>$event['language']??null,'start_sample'=>isset($event['start_ms'])?$base+$event['start_ms']*16:null,'end_sample'=>isset($event['end_ms'])?$base+$event['end_ms']*16:null,'timing_precision'=>$event['timing_precision']??'unavailable','mapping'=>$event['mapping']??'unavailable','updated_at'=>now()];
   if($old){if($old->status==='completed')$data['original_text']=$old->original_text;DB::table('subtitle_units')->where('id',$old->id)->update($data);$uid=$old->id;}else{$uid=DB::table('subtitle_units')->insertGetId($data);}
   $unit=Access::unit(DB::table('subtitle_units')->find($uid));Access::event($id,'subtitle.updated',['unit'=>$unit]);
   return true;
  });
  if($changed&&$this->backendPid&&!$this->captionPumpPending){
   $this->captionPumpPending=true;
   Timer::add(0.025,function(){$this->captionPumpPending=false;$this->pump();},[],false);
  }
 }
 private function handoffCommand(TcpConnection $c,object $l,object $u,array $msg):void {
  $service=new RecorderHandoffs;$key=$c->context->key;$id=(int)$l->id;$type=$msg['type'];
  if(in_array($type,['recording.invite','recording.request'])){
   if(($msg['confirmed']??false)!==true)throw new Failure('confirmation_required');$target=null;
   foreach($this->clients as $targetKey=>$candidate){if($candidate['lesson_id']!==$id||$targetKey===$key)continue;
    if($type==='recording.invite'&&hash_equals(hash('sha256',$targetKey),(string)($msg['target_client_id']??'')))$target=$candidate;
    if($type==='recording.request'&&(int)$candidate['user_id']===(int)$l->recorder_user_id&&(!$l->recorder_connection||$l->recorder_connection===$targetKey))$target=$candidate;
   }
   if(!$target)throw new Failure('handoff_target_offline',409);$targetUser=$this->actor($target['connection']);Access::member($targetUser);Access::lesson($id,$targetUser,$target['session_id']);
   $service->create($id,$u,$key,$targetUser,$target['connection']->context->key,$type==='recording.invite'?'invite':'request',(string)($msg['idempotency_key']??''));$this->notifyHandoffs($id);return;
  }
  if($type==='recording.respond'){
   $h=$service->respond($id,$u,$key,(string)($msg['id']??''),(string)($msg['action']??''));$this->notifyHandoffs($id);
   if($h->status==='preparing'&&isset($this->clients[$h->target_connection]))$this->send($this->clients[$h->target_connection]['connection'],['type'=>'recording.prepare','id'=>$h->id,'live'=>$l->status==='live']);return;
  }
  if($type==='recording.prepared'){
   $ready=($msg['ready']??false)===true;$old=$service->prepared($id,$u,$key,(string)($msg['id']??''),$ready);$this->notifyHandoffs($id);if(!$ready)return;
   $this->completeSwitch($c,$id,$u,$old->status==='live',(string)$msg['id']);return;
  }
  if($type==='recording.takeover'){
   if(($msg['confirmed']??false)!==true||($msg['prepared']??false)!==true)throw new Failure('confirmation_required');$force=($msg['force']??false)===true;
   if($force){Access::admin($u);$session=DB::table('sessions')->where('id',$this->clients[$key]['session_id'])->first();$decoded=@unserialize(base64_decode($session->payload),['allowed_classes'=>false]);if(!is_array($decoded)||($decoded['reauth_at']??0)<time()-900)throw new Failure('reauth_required',403);}
   $holderOnline=false;foreach($this->clients as $peer)if($peer['lesson_id']===$id&&(int)$peer['user_id']===(int)$l->recorder_user_id&&$peer['connection']->context->key!==$key)$holderOnline=true;
   $old=$service->takeover($id,$u,$force,$holderOnline);$this->notifyHandoffs($id);$this->completeSwitch($c,$id,$u,$old->status==='live','takeover');return;
  }
  throw new Failure('unknown_command');
 }
 private function completeSwitch(TcpConnection $target,int $id,object $u,bool $live,string $invitation):void {
  unset($this->recorderReconnect[$id],$this->recovering[$id]);
  AudioStore::flushLesson($id);if(isset($this->preparing[$id]))$this->preparing[$id]['peer']->close();
  if($live){$this->pendingStarts[$id]=$target->context->key;if(isset($this->upstreams[$id]))$this->upstreams[$id]['peer']->finish();else{$this->start($target,Access::lesson($id,$u,$this->clients[$target->context->key]['session_id']),$u);unset($this->pendingStarts[$id]);}}
  else $this->send($target,['type'=>'recording.transferred','id'=>$invitation,'resume'=>false]);
 }
 private function notifyHandoffs(int $id):void {foreach($this->clients as $key=>$a)if($a['lesson_id']===$id&&$a['user_id']){try{$u=$this->actor($a['connection']);Access::member($u);$this->send($a['connection'],['type'=>'handoffs','items'=>(new RecorderHandoffs)->items($id,(int)$u->id,$key)]);}catch(Failure){}}}
 /** Batch fresh authorization per event-loop turn; audio commands still revalidate individually. */
 private function authorizedClients():array {
  if(!$this->clients)return [];$sessions=DB::table('sessions')->whereIn('id',array_unique(array_column($this->clients,'session_id')))->get()->keyBy('id');$ids=array_values(array_unique(array_filter(array_column($this->clients,'user_id'))));$users=$ids?DB::table('users')->whereIn('id',$ids)->where('disabled',false)->get()->keyBy('id'):collect();
  $lessons=DB::table('lessons as l')->join('courses as c','c.id','=','l.course_id')->whereIn('l.id',array_unique(array_column($this->clients,'lesson_id')))->whereNull('l.deleted_at')->whereNull('c.deleted_at')->where('c.enabled',true)->select('l.id','l.media_samples','c.guest_access')->get()->keyBy('id');$valid=[];
  foreach($this->clients as$key=>$client){$s=$sessions[$client['session_id']]??null;$u=$client['user_id']?($users[$client['user_id']]??null):null;$l=$lessons[$client['lesson_id']]??null;$allowed=$s&&$s->last_activity>=time()-config('session.lifetime')*60&&$l;
   if($client['user_id'])$allowed=$allowed&&$u&&(int)$s->user_id===(int)$u->id&&(int)$s->auth_version===(int)$u->auth_version;
   if($allowed&&(!$u||$u->must_change_password)&&$l->guest_access!=='public')$allowed=\App\Sharing\SharingService::hasGrant($client['session_id'],$client['lesson_id']);
   if(!$allowed){$this->send($client['connection'],['type'=>'error','code'=>'access_revoked']);$this->pauseConnection($client['connection'],'session_revoked');$client['connection']->close();continue;}$valid[$key]=[...$client,'_user'=>$u,'_lesson'=>$l];
  }return $valid;
 }
 private function presence():void {
  $clients=$this->authorizedClients();$rooms=[];foreach($clients as$key=>$a){$u=$a['_user'];$rooms[$a['lesson_id']]['count']=($rooms[$a['lesson_id']]['count']??0)+1;if($u&&!$u->must_change_password)$rooms[$a['lesson_id']]['participants'][]=['client_id'=>hash('sha256',$key),'user_id'=>(int)$u->id,'name'=>$u->name];}
  foreach($clients as$key=>$a){$room=$rooms[$a['lesson_id']];$out=['type'=>'presence','count'=>$room['count'],'current_client_id'=>hash('sha256',$key)];if($a['_user']&&!$a['_user']->must_change_password)$out['participants']=$room['participants']??[];$this->send($a['connection'],$out);$this->send($a['connection'],['type'=>'lesson.progress','media_samples'=>(int)$a['_lesson']->media_samples]);}
 }

 private function pauseConnection(TcpConnection $c,string $reason):void {if($this->emergency)return;try{$a=$this->clients[$c->context->key]??null;if($a&&DB::table('lessons')->where('id',$a['lesson_id'])->value('recorder_connection')===$c->context->key)$this->stop($a['lesson_id'],false,$reason);}catch(\Throwable){$c->close();}}
 private function pump():void {
  try{
   $this->assertSingleton();$events=DB::table('lesson_events')->whereNull('broadcasted_at')->orderBy('seq')->limit(500)->get();$valid=$events->isEmpty()?[]:$this->authorizedClients();
   foreach($events as $e){
    $payload=json_decode($e->payload,true);
    if($e->event_type==='control.end'){$admin=Access::user((int)($payload['actor_id']??0));if($admin&&$admin->role==='admin'&&!$admin->must_change_password)$this->stop((int)$e->lesson_id,true,'admin_end');}
    else foreach($valid as $client)if($client['lesson_id']===(int)$e->lesson_id){
     try{$this->send($client['connection'],['type'=>'event','seq'=>(int)$e->seq,'event_type'=>$e->event_type,'payload'=>$payload]);}
     catch(Failure){$this->send($client['connection'],['type'=>'error','code'=>'access_revoked']);$client['connection']->close();}
    }
    if($e->event_type==='handoff.updated')$this->notifyHandoffs((int)$e->lesson_id);
    DB::table('lesson_events')->where('seq',$e->seq)->update(['broadcasted_at'=>now()]);
   }
  }catch(\Throwable){$this->emergencyStop();}
 }
 private function watchdog():void {
  $this->policies=\App\Settings\Settings::policies();(new RecorderHandoffs)->expire();
  foreach($this->clients as$a){$c=$a['connection'];try{$u=$this->actor($c);Access::lesson($a['lesson_id'],$u,$a['session_id']);if($a['heartbeat']<time()-config('classroom.lease_seconds'))$c->close();}catch(Failure){$this->pauseConnection($c,'session_revoked');$c->close();}}
  foreach($this->recorderReconnect as$id=>$resume){
   if($resume['until']===null||microtime(true)<$resume['until'])continue;
   unset($this->recorderReconnect[$id]);
   if(DB::table('lessons')->where('id',$id)->where('status','live')->where('recorder_connection',$resume['key'])->where('recorder_epoch',$resume['epoch'])->exists())$this->stop((int)$id,false,'recorder_disconnected');
  }
  foreach($this->recovering as$id=>$recovery)if(microtime(true)>=$recovery['retry_at'])$this->recoverTranslation((int)$id,$recovery);
  foreach($this->upstreams as$id=>$up){
   $elapsed=$up['peer']->elapsedSeconds();$soft=$this->policies['rotation_soft_seconds'];$hard=$this->policies['rotation_hard_seconds'];
   if(!empty($this->preparing[$id]['ready'])&&($up['peer']->canRotateAtBoundary()||$elapsed>=$hard)){
    $lesson=DB::table('lessons')->where('id',$id)->where('status','live')->whereNull('purge_status')->first();
    if($lesson)$this->switchRotation((int)$id,$lesson);
    if(($this->upstreams[$id]['sid']??null)!==$up['sid'])continue;
   }
   if($elapsed>$hard+$up['cfg']->connectTimeout){$up['peer']->close();continue;}
   if($elapsed>=$soft&&microtime(true)>=$up['next_rotation']&&($up['peer']->canRotateAtBoundary()||$elapsed>=max($soft,$hard-$up['cfg']->connectTimeout-4)))$this->prepareRotation((int)$id,$elapsed>=$hard);
  }
 }
 private function guard(callable $work):void {if($this->emergency)return;try{$work();}catch(\Throwable){$this->emergencyStop();}}
 private function emergencyStop():void {if($this->emergency)return;$this->emergency=true;foreach($this->clients as $client){$this->send($client['connection'],['type'=>'error','code'=>'database_unavailable']);$client['connection']->close();}foreach($this->upstreams as $up)$up['peer']->close();foreach($this->preparing as$up)$up['peer']->close();foreach($this->draining as$room)foreach($room as$up)$up['peer']->close();$this->upstreams=[];logger()->error('Gateway stopped after persistence or singleton-lock failure; supervisor restart required');Worker::stopAll();}
 private function assertSingleton():void {if($this->backendPid&&(int)DB::selectOne('SELECT pg_backend_pid() AS pid')->pid!==$this->backendPid){$this->emergencyStop();throw new Failure('gateway_lock_lost',503);}}
}
