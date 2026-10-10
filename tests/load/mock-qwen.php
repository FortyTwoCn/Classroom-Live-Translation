<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Workerman\Worker;
$worker=new Worker('websocket://127.0.0.1:28082');$worker->name='EXPLICIT-LOAD-MOCK-QWEN';
Worker::$logFile=storage_path('logs/mock-qwen.log');
Worker::$pidFile=storage_path('framework/mock-qwen.pid');
$log=function(array $r){file_put_contents(storage_path('../mock-metrics.ndjson'),json_encode($r,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);};
$emit=function($c,array $e){$e['event_id']=$c->context->id.'-'.++$c->context->events;$c->send(json_encode($e,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));return $e;};
$complete=function($c)use($emit,$log){if($c->context->samples===$c->context->lastCompleted)return;$n=++$c->context->utterance;$source=$c->context->id.'-s'.$n;$target=$c->context->id.'-t'.$n;$response=$c->context->id.'-r'.$n;$ms=(int)round(microtime(true)*1000);$text='[LOAD #'.$c->context->serial.' @'.$ms.'] Artificial sentence '.$n;
 $emit($c,['type'=>'input_audio_buffer.speech_started','item_id'=>$source,'audio_start_ms'=>intdiv($c->context->lastCompleted,16)]);
 $emit($c,['type'=>'conversation.item.input_audio_transcription.delta','item_id'=>$source,'content_index'=>0,'delta'=>$text]);
 $emit($c,['type'=>'conversation.item.created','previous_item_id'=>$source,'item'=>['id'=>$target,'role'=>'assistant']]);
 $emit($c,['type'=>'response.text.delta','response_id'=>$response,'item_id'=>$target,'content_index'=>0,'delta'=>$text.' 人工译文']);
 $emit($c,['type'=>'input_audio_buffer.speech_stopped','item_id'=>$source,'audio_end_ms'=>intdiv($c->context->samples,16)]);
 $emit($c,['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>$source,'content_index'=>0,'transcript'=>$text]);
 $emit($c,['type'=>'response.text.done','response_id'=>$response,'item_id'=>$target,'content_index'=>0,'text'=>$text.' 人工译文']);
 $done=$emit($c,['type'=>'response.done','response'=>['id'=>$response,'status'=>'completed','output'=>[['id'=>$target,'type'=>'message','role'=>'assistant','content'=>[['type'=>'text','text'=>$text.' 人工译文']]]],'usage'=>['total_tokens'=>20]]]);
 $c->send(json_encode($done,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); // Duplicate final event must be deduplicated.
 $c->context->lastCompleted=$c->context->samples;$log(['type'=>'response','connection'=>$c->context->id,'serial'=>$c->context->serial,'at_ms'=>$ms,'utterance'=>$n]);
};
$worker->onWebSocketConnect=function($c)use($emit,$log){$c->context->id='load-'.bin2hex(random_bytes(8));$c->context->events=0;$c->context->frames=0;$c->context->samples=0;$c->context->lastCompleted=0;$c->context->utterance=0;$c->context->serial=0;$emit($c,['type'=>'session.created','session'=>['id'=>$c->context->id,'model'=>'qwen3.8-livetranslate-flash-realtime']]);$log(['type'=>'connected','connection'=>$c->context->id,'at_ms'=>(int)round(microtime(true)*1000)]);};
$worker->onMessage=function($c,$message)use($emit,$complete,$log){$e=json_decode($message,true,16,JSON_THROW_ON_ERROR);$type=$e['type']??'';
 if($type==='session.update'){if(($e['session']['output_modalities']??[])!==['text'])throw new RuntimeException('load_text_only_required');$emit($c,['type'=>'session.updated','session'=>$e['session']]);}
 if($type==='input_audio_buffer.append'){$pcm=base64_decode($e['audio'],true);$bytes=strlen($pcm);if($bytes!==3200)throw new RuntimeException('load_normative_100ms_pcm_required');$c->context->samples+=1600;$c->context->serial=unpack('Vserial',substr($pcm,0,4))['serial'];$log(['type'=>'audio','connection'=>$c->context->id,'serial'=>$c->context->serial,'at_ms'=>(int)round(microtime(true)*1000)]);if(++$c->context->frames%50===0)$complete($c);}
 if($type==='session.finish'){$complete($c);$emit($c,['type'=>'session.finished','session'=>['id'=>$c->context->id]]);$log(['type'=>'finished','connection'=>$c->context->id,'frames'=>$c->context->frames,'samples'=>$c->context->samples,'at_ms'=>(int)round(microtime(true)*1000)]);}
};
Worker::runAll();
