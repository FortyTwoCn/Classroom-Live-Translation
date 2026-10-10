#!/usr/bin/env php
<?php
declare(strict_types=1);
use App\Translation\{QwenConfig,QwenSession};
use Workerman\{Timer,Worker};
use Workerman\Events\Select;

/** Real-only, no business database writes, at most 9 calls / 180 elapsed seconds. */
$report=['test_kind'=>'real_model_phrases','started_at'=>gmdate(DATE_ATOM),'status'=>'NOT_RUN','supplier_maximum_claimed'=>false,'application_caps'=>null,'budget'=>['max_calls'=>9,'max_elapsed_seconds'=>180,'min_start_interval_seconds'=>6.3],'device_capture_verified'=>false,'language_quality_verified'=>false,'transcript_storage'=>'counts_and_matching_booleans_only','cases'=>[]];
$print=static fn(array $v)=>fwrite(STDOUT,json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
if(!is_file(__DIR__.'/../vendor/autoload.php')){$report['status']='SKIP';$report['reason']='dependencies_missing';$print($report);exit(2);}
$app=require __DIR__.'/bootstrap.php';$base=config('classroom.qwen',[]);$config=QwenConfig::fromArray($base);
$report['application_caps']=['count'=>1000,'source_bytes'=>QwenConfig::MAX_PHRASE_SOURCE_BYTES,'target_bytes'=>QwenConfig::MAX_PHRASE_TARGET_BYTES,'session_update_bytes'=>QwenConfig::MAX_SESSION_UPDATE_BYTES];
if($config->missing()!==[]){$report['status']='SKIP';$report['reason']='configuration_missing';$report['missing']=$config->missing();$print($report);exit(2);}
try{$config->validate();if($config->region!=='cn-beijing'||in_array(parse_url($config->endpoint,PHP_URL_HOST),['localhost','127.0.0.1','[::1]'],true))throw new \InvalidArgumentException('REAL_PHRASES_PROBE_REQUIRES_BEIJING');}catch(\Throwable $e){$report['status']='SKIP';$report['reason']=$e instanceof \InvalidArgumentException?$e->getMessage():'configuration_invalid';$print($report);exit(2);}
$path=$argv[1]??'';$bytes=is_string($path)&&is_file($path)?filesize($path):false;
if($bytes===false||$bytes<3200||$bytes>32000*15||$bytes%2){$report['status']='SKIP';$report['reason']='supply_raw_pcm_speech_0_1_to_15_seconds_containing_airfoil';$print($report);exit(2);}
$pcm=file_get_contents($path);if($pcm===false||substr($pcm,0,4)==='RIFF'){$report['status']='FAIL';$report['reason']='raw_pcm_required';$print($report);exit(1);}
$report+=['model'=>$config->model,'region'=>$config->region,'input_samples'=>intdiv($bytes,2),'input_sha256'=>hash('sha256',$pcm),'target_language'=>'zh'];
$cases=[['name'=>'baseline','phrases'=>[],'preferred'=>null],['name'=>'preferred_1','phrases'=>['airfoil'=>'课堂术语X314'],'preferred'=>'X314'],['name'=>'academic_3','phrases'=>['airfoil'=>'翼型','angle of attack'=>'迎角','lift coefficient'=>'升力系数'],'preferred'=>'翼型']];
foreach([16,128,512,1000]as $count){$map=['airfoil'=>'课堂术语X314'];for($i=1;$i<$count;$i++)$map['glossary_term_'.str_pad((string)$i,4,'0',STR_PAD_LEFT)]='术语'.str_pad((string)$i,4,'0',STR_PAD_LEFT);$cases[]=['name'=>'count_'.$count,'phrases'=>$map,'preferred'=>'X314'];}
$cases[]=['name'=>'source_bytes_'.QwenConfig::MAX_PHRASE_SOURCE_BYTES,'phrases'=>[str_repeat('A',QwenConfig::MAX_PHRASE_SOURCE_BYTES)=>'长源词测试'],'preferred'=>null];
$cases[]=['name'=>'natural_chinese','phrases'=>['airfoil'=>'用于产生空气动力升力的机翼横截面形状'],'preferred'=>'用于产生空气动力升力的机翼横截面形状'];
if(($argv[2]??null)==='--natural'){
    $cases=[['name'=>'natural_chinese','language'=>'zh','phrases'=>['airfoil'=>'用于产生空气动力升力的机翼横截面形状'],'preferred'=>'用于产生空气动力升力的机翼横截面形状'],['name'=>'natural_ukrainian','language'=>'uk','phrases'=>['airfoil'=>'аеродинамічний профіль'],'preferred'=>'аеродинамічний профіль']];
    $report['probe_plan']='natural_term_followup';$report['budget']['max_calls']=2;$report['target_language']='mixed';
}elseif(isset($argv[2])){
    if(!preg_match('/^--target-lengths=(64|128|256)(?:,(64|128|256)){0,2}$/D',$argv[2])){$report['status']='SKIP';$report['reason']='invalid_bounded_followup_plan';$print($report);exit(2);}
    $lengths=array_unique(array_map('intval',explode(',',substr($argv[2],strlen('--target-lengths=')))));$cases=[];
    foreach($lengths as $length)$cases[]=['name'=>'target_bytes_'.$length,'phrases'=>['airfoil'=>'LONGTARGET_'.str_repeat('A',$length-11)],'preferred'=>'LONGTARGET_'];
    $report['probe_plan']='target_length_followup';$report['budget']['max_calls']=count($cases);
}
$started=hrtime(true)/1e9;$lastStart=-INF;$allAccepted=true;$effects=true;$calls=0;
foreach($cases as $index=>$case){
    $remaining=180-(hrtime(true)/1e9-$started);$wait=max(0,6.3-(hrtime(true)/1e9-$lastStart));
    $language=$case['language']??'zh';
    $result=['name'=>$case['name'],'target_language'=>$language,'status'=>'NOT_RUN','phrase_count'=>count($case['phrases']),'source_max_bytes'=>$case['phrases']===[]?0:max(array_map('strlen',array_keys($case['phrases']))),'target_max_bytes'=>$case['phrases']===[]?0:max(array_map('strlen',array_values($case['phrases']))),'checks'=>['configuration_accepted'=>false,'echo_match'=>false,'source_recognized'=>false,'preferred_translation_observed'=>null],'source_final_count'=>0,'translation_final_count'=>0,'usage'=>[],'errors'=>[]];
    if($calls>=$report['budget']['max_calls']||$remaining<$bytes/32000+$wait+5){$result['reason']='probe_budget_exhausted';$report['cases'][]=$result;$allAccepted=false;continue;}
    try{$values=$base;$values['phrases']=$case['phrases'];$values['connect_timeout']=15;$values['finish_timeout']=15;$cfg=QwenConfig::fromArray($values);$update=$cfg->sessionUpdate($language);$result['session_update_bytes']=QwenConfig::updateBytes($update);$result['requested_hash']=hash('sha256',json_encode((object)$case['phrases'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    catch(\Throwable){$result['status']='FAIL';$result['errors'][]=['code'=>'local_configuration_rejected'];$report['cases'][]=$result;$allAccepted=false;continue;}
    $loop=new Select();Worker::$globalEvent=$loop;Timer::init($loop);$session=null;$sendTimer=null;$done=false;$offset=0;$units=[];$responses=[];$stopCalled=false;
    $stop=static function()use(&$stopCalled,&$sendTimer,$loop):void{if($stopCalled)return;$stopCalled=true;if($sendTimer!==null){Timer::del($sendTimer);$sendTimer=null;}Timer::add(0.01,fn()=>$loop->stop(),[],false);};
    $loop->setErrorHandler(static function(\Throwable $e)use(&$result,&$session,$stop):void{$result['errors'][]=['code'=>'event_loop_error'];$session?->close();$stop();});
    Timer::add(max(0.001,$wait),function()use(&$session,&$result,&$sendTimer,&$offset,&$units,&$responses,&$done,&$lastStart,&$calls,$cfg,$language,$pcm,$stop):void{
        $lastStart=hrtime(true)/1e9;++$calls;
        try{$session=new QwenSession($cfg,$language,static function(array $event)use(&$units,&$responses,&$result):void{if($event['type']==='subtitle.upsert')$units[$event['unit_id']]=$event;elseif($event['type']==='usage')$result['usage'][$event['response_id']]=$event['usage'];elseif($event['type']==='response.status')$responses[$event['response_id']]=$event['status'];elseif($event['type']==='upstream.error')$result['errors'][]=['code'=>$event['code'],'category'=>$event['category']];},function(string $state,array $details)use(&$session,&$result,&$sendTimer,&$offset,&$done,$pcm,$stop):void{
            if($state==='ready'){$result['checks']['configuration_accepted']=true;$result['checks']['echo_match']=($details['phrases_echo_match']??false)===true;$sendTimer=Timer::add(0.1,function()use(&$session,&$sendTimer,&$offset,$pcm):void{$part=substr($pcm,$offset,3200);if($part===''){Timer::del($sendTimer);$sendTimer=null;$session->finish();}else{$session->sendAudio($part);$offset+=strlen($part);}});}
            elseif($state==='finished'){$done=true;$stop();}elseif(in_array($state,['failed','closed'],true)){$result['errors'][]=['code'=>$details['code']??'closed_before_finish'];$stop();}
        });$session->connect();}catch(\Throwable){$result['errors'][]=['code'=>'probe_start_failed'];$stop();}
    },[],false);
    Timer::add(max(0.01,$remaining),function()use(&$result,&$session,$stop):void{$result['errors'][]=['code'=>'probe_elapsed_budget_exhausted'];$session?->close();$stop();},[],false);
    $loop->run();$session?->close();$source='';$translation='';
    foreach($units as $unit)if($unit['status']==='completed'&&trim($unit['text'])!==''){if($unit['kind']==='source'){++$result['source_final_count'];$source.=' '.$unit['text'];}else{++$result['translation_final_count'];$translation.=' '.$unit['text'];}}
    $result['checks']['source_recognized']=(bool)preg_match('/air\s*foil/i',$source);$result['checks']['preferred_translation_observed']=$case['preferred']===null?null:str_contains(mb_strtolower(preg_replace('/\s+/u','',$translation)),mb_strtolower(preg_replace('/\s+/u','',$case['preferred'])));
    $result['checks']['all_audio_sent']=$offset===$bytes;$result['checks']['session_finished']=$done;$result['checks']['translation_nonempty']=$result['translation_final_count']>0;$result['response_status_counts']=array_count_values($responses);
    $result['sent_samples']=$session?->sentSamplesCount()??0;
    $result['checks']['no_unfinished_response']=count(array_filter($responses,fn($s)=>$s!=='completed'))===0;
    $accepted=$result['checks']['configuration_accepted']&&$result['checks']['echo_match']&&$done&&$result['errors']===[];$speech=$result['checks']['source_recognized']&&$result['checks']['translation_nonempty']&&$result['checks']['all_audio_sent']&&$result['checks']['no_unfinished_response'];
    $result['status']=$accepted&&$speech&&$result['checks']['preferred_translation_observed']!==false?'PASS':($accepted&&$speech?'PARTIAL':'FAIL');
    if(!$accepted)$allAccepted=false;if($result['checks']['preferred_translation_observed']===false)$effects=false;
    $report['cases'][]=$result;fwrite(STDERR,'Real phrases case '.($index+1).': '.$result['name'].' '.$result['status'].PHP_EOL);
}
$report['calls']=$calls;$report['elapsed_seconds']=round(hrtime(true)/1e9-$started,3);$report['status']=$allAccepted&&$effects&&!in_array('FAIL',array_column($report['cases'],'status'),true)?'PASS':'PARTIAL';$report['finished_at']=gmdate(DATE_ATOM);$report['scope']='Only listed values and this speech fixture were tested; no supplier maximum inferred.';$print($report);exit($report['status']==='PASS'?0:1);
