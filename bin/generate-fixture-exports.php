<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
// Explicit synthetic provider content: these samples are not evidence of model integration.
$metadata=['course_name'=>'Fixture 课程','lesson_name'=>'Fixture Урок','lesson_created_at'=>'2026-10-09T10:00:00Z','source_language'=>'en','target_language'=>'zh','exported_at'=>'2026-10-09T11:00:00Z','media_samples'=>48000];
$common=['model_session_id'=>'fixture-session','content_index'=>0,'start_sample'=>16000,'end_sample'=>32000,'timing_precision'=>'server_segment'];
$directory=__DIR__.'/../docs/examples';if(!is_dir($directory))mkdir($directory,0755,true);
$summary=[];
foreach(['txt','md','srt','vtt'] as $format)foreach(['source','translation','bilingual'] as $layout)foreach(['original','current'] as $version){
    $rows=[['id'=>1,'kind'=>'source','item_id'=>'fixture-asr','source_item_id'=>'fixture-asr','mapping'=>'source_identity','text'=>$version==='original'?'Hello, university.':'Hello, classroom.']+$common,
        ['id'=>2,'kind'=>'translation','item_id'=>'fixture-translated','source_item_id'=>'fixture-asr','mapping'=>'explicit_previous_item_id','text'=>'你好，课堂。 Привіт, аудиторіє. <special & characters>']+$common];
    $result=(new App\Exports\SubtitleFormatter)->render($rows,['format'=>$format,'layout'=>$layout,'text_version'=>$version],$metadata);
    $filename='fixture-'.$layout.'-'.$version.'.'.$format;file_put_contents($directory.'/'.$filename,$result['contents']);$summary[$filename]=['counts'=>$result['counts'],'warnings'=>$result['warnings']];
}
file_put_contents($directory.'/fixture-report.json',json_encode(['test_kind'=>'synthetic_content_real_files','model_called'=>false,'files'=>$summary],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
fwrite(STDOUT,"Generated 24 explicit fixture exports; no model or OSS call.\n");
