<?php
// Explicit protocol fixture; NEVER loaded by application production code.
require __DIR__.'/../../vendor/autoload.php';
use Workerman\Worker;
$worker=new Worker(getenv('MOCK_QWEN_LISTEN')?:'websocket://127.0.0.1:18082');$worker->name='EXPLICIT-MOCK-QWEN';
$emit=function($c,array $e){$e['event_id']='fixture-'.++$c->context->events;$c->send(json_encode($e,JSON_UNESCAPED_UNICODE));};
$worker->onWebSocketConnect=function($c)use($emit){$c->context->events=0;$c->context->frames=0;$c->context->samples=0;$emit($c,['type'=>'session.created','session'=>['id'=>'fixture-session','model'=>'qwen3.8-livetranslate-flash-realtime']]);};
$worker->onMessage=function($c,$message)use($emit){$e=json_decode($message,true);$type=$e['type']??'';
 if($type==='session.update'){if(($e['session']['output_modalities']??[])!==['text'])throw new RuntimeException('text_only_required');$emit($c,['type'=>'session.updated','session'=>$e['session']]);}
 if($type==='input_audio_buffer.append'){
  $bytes=strlen(base64_decode($e['audio'],true));if($bytes<2||$bytes>3200||$bytes%2)throw new RuntimeException('pcm_100ms_or_tail_required');$c->context->samples+=intdiv($bytes,2);
  if(++$c->context->frames===3){
   $emit($c,['type'=>'input_audio_buffer.speech_started','item_id'=>'source-A','audio_start_ms'=>0]);
   $emit($c,['type'=>'conversation.item.input_audio_transcription.delta','item_id'=>'source-A','content_index'=>0,'delta'=>'Lift coefficient']);
   $emit($c,['type'=>'conversation.item.created','previous_item_id'=>'source-A','item'=>['id'=>'translation-B','role'=>'assistant']]);
   $emit($c,['type'=>'response.text.delta','response_id'=>'response-C','item_id'=>'translation-B','content_index'=>0,'delta'=>'升力系数']);
  }
 }
 if($type==='session.finish'){
  $emit($c,['type'=>'input_audio_buffer.speech_stopped','item_id'=>'source-A','audio_end_ms'=>(int)floor($c->context->samples/16)]);
  $emit($c,['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'source-A','content_index'=>0,'transcript'=>'Lift coefficient.']);
  $emit($c,['type'=>'response.text.done','response_id'=>'response-C','item_id'=>'translation-B','content_index'=>0,'text'=>'升力系数。']);
  $done=['type'=>'response.done','response'=>['id'=>'response-C','status'=>'completed','output'=>[['id'=>'translation-B','type'=>'message','role'=>'assistant','content'=>[['type'=>'text','text'=>'升力系数。']]]],'usage'=>['total_tokens'=>20]]];
  $emit($c,$done);$same=json_encode(['event_id'=>'fixture-'.$c->context->events]+$done,JSON_UNESCAPED_UNICODE);$c->send($same); // repeat SAME final event
  $emit($c,['type'=>'session.finished','session'=>['id'=>'fixture-session']]);
 }
};
Worker::runAll();
