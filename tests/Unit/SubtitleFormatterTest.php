<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Exports\SubtitleFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubtitleFormatterTest extends TestCase
{
    public static function combinations():array
    {
        $data=[];foreach(['txt','md','srt','vtt'] as $format)foreach(['source','translation','bilingual'] as $layout)foreach(['original','current'] as $version)$data[$format.'-'.$layout.'-'.$version]=[$format,$layout,$version];return $data;
    }
    #[DataProvider('combinations')]
    public function test_four_formats_all_layouts_and_versions(string $format,string $layout,string $version):void
    {
        $result=(new SubtitleFormatter)->render($this->rows($version),compact('format','layout')+['text_version'=>$version],$this->metadata());
        $text=$result['contents'];
        self::assertSame(1,$result['counts']['exported_segments']);
        self::assertSame(0,$result['counts']['skipped_timing']);
        if($layout!=='translation')self::assertStringContainsString($version==='current'?'Edited English':'English original',$text);
        else self::assertStringNotContainsString('English original',$text);
        if($layout!=='source')self::assertStringContainsString('你好 Привіт',$text);
        if($layout==='bilingual')self::assertLessThan(strpos($text,'你好'),strpos($text,$version==='current'?'Edited English':'English original'));
        if($format==='srt'){self::assertStringStartsWith("1\n00:00:01,000 --> 00:00:02,000\n",$text);self::assertStringNotContainsString('Course:',$text);}
        if($format==='vtt'){self::assertStringStartsWith("WEBVTT\n\nNOTE\nCourse:",$text);self::assertStringContainsString('00:00:01.000 --> 00:00:02.000',$text);}
        if(in_array($format,['srt','vtt'],true))self::assertStringContainsString('&lt;script&gt;',$text);
    }
    public function test_unrelated_items_never_pair_and_missing_timing_is_explicit():void
    {
        $rows=$this->rows('original');$rows[1]['source_item_id']=null;$rows[1]['mapping']='unavailable';$rows[1]['start_sample']=$rows[1]['end_sample']=null;
        $result=(new SubtitleFormatter)->render($rows,['format'=>'srt','layout'=>'bilingual','text_version'=>'original'],$this->metadata());
        self::assertSame(2,$result['counts']['unpaired_segments']);self::assertSame(1,$result['counts']['missing_source']);self::assertSame(1,$result['counts']['missing_translation']);
        self::assertSame(1,$result['counts']['skipped_timing']);self::assertSame(1,$result['counts']['missing_timing']);self::assertSame(1,$result['counts']['exported_segments']);self::assertStringNotContainsString('你好',$result['contents']);
        $txt=(new SubtitleFormatter)->render($rows,['format'=>'txt','layout'=>'bilingual','text_version'=>'original'],$this->metadata());self::assertSame(2,$txt['counts']['exported_segments']);self::assertStringContainsString('[time unavailable]',$txt['contents']);self::assertStringContainsString('你好',$txt['contents']);
    }
    public function test_media_mapping_beyond_recording_and_zero_duration_never_get_fabricated_cues():void
    {
        $rows=$this->rows('original');$rows[0]['end_sample']=999999;$rows[1]['end_sample']=$rows[1]['start_sample'];
        $result=(new SubtitleFormatter)->render($rows,['format'=>'vtt','layout'=>'bilingual','text_version'=>'original'],$this->metadata());
        self::assertSame(1,$result['counts']['invalid_timing']);self::assertSame(1,$result['counts']['skipped_timing']);self::assertSame(0,$result['counts']['exported_segments']);self::assertStringNotContainsString(' --> ',$result['contents']);
    }
    public function test_multiple_model_sessions_cannot_share_source_identity():void
    {
        $rows=$this->rows('original');$rows[1]['model_session_id']='another-session';
        $result=(new SubtitleFormatter)->render($rows,['format'=>'txt','layout'=>'bilingual','text_version'=>'original'],$this->metadata());self::assertSame(2,$result['counts']['exported_segments']);self::assertSame(2,$result['counts']['unpaired_segments']);
    }
    public function test_blank_lines_and_metadata_do_not_break_vtt_grammar():void
    {
        $rows=$this->rows('original');$rows[0]['text']="A\n\nB --> C";$metadata=$this->metadata();$metadata['course_name']="course\n\n00:01 --> 00:02";
        $result=(new SubtitleFormatter)->render($rows,['format'=>'vtt','layout'=>'source','text_version'=>'original'],$metadata);
        self::assertSame(1,$result['counts']['normalized_blank_lines']);self::assertStringContainsString("A\nB --&gt; C",$result['contents']);self::assertSame(1,substr_count($result['contents'],' --> '));
    }
    public function test_absolute_media_samples_across_pauses_and_model_rotation_define_timeline():void
    {
        $rows=$this->rows('original');$second=$rows[0];$second['id']=3;$second['model_session_id']='rotated-model';$second['item_id']=$second['source_item_id']='next';$second['text']='After pause';$second['start_sample']=48000;$second['end_sample']=64000;$rows[]=$second;$metadata=$this->metadata();$metadata['media_samples']=64000;
        $result=(new SubtitleFormatter)->render($rows,['format'=>'srt','layout'=>'source','text_version'=>'original'],$metadata);self::assertStringContainsString("2\n00:00:03,000 --> 00:00:04,000\nAfter pause",$result['contents']);self::assertSame(2,$result['counts']['exported_segments']);
    }
    public function test_offsets_with_unverified_precision_do_not_form_timed_cues():void
    {
        $rows=$this->rows('original');$rows[0]['timing_precision']='unavailable';$result=(new SubtitleFormatter)->render($rows,['format'=>'srt','layout'=>'source','text_version'=>'original'],$this->metadata());self::assertSame(1,$result['counts']['invalid_timing']);self::assertSame(0,$result['counts']['exported_segments']);
    }
    private function rows(string $version):array
    {
        $common=['model_session_id'=>'session','content_index'=>0,'start_sample'=>16000,'end_sample'=>32000,'timing_precision'=>'server_segment'];
        return [['id'=>1,'kind'=>'source','item_id'=>'asr','source_item_id'=>'asr','mapping'=>'source_identity','text'=>($version==='current'?'Edited English':'English original').' <script>']+$common,
            ['id'=>2,'kind'=>'translation','item_id'=>'different-provider-item','source_item_id'=>'asr','mapping'=>'explicit_previous_item_id','text'=>'你好 Привіт <script>']+$common];
    }
    public function test_blank_snapshot_rows_never_generate_empty_export_segments():void
    {
        $rows=$this->rows('original');$rows[0]['text']="\u{200B}\u{3000}";
        $result=(new SubtitleFormatter)->render($rows,['format'=>'txt','layout'=>'bilingual','text_version'=>'original'],$this->metadata());
        self::assertSame(1,$result['counts']['skipped_empty']);
        self::assertSame(1,$result['counts']['selected_units']);
        self::assertSame(1,$result['counts']['exported_segments']);
        self::assertStringContainsString('你好 Привіт',$result['contents']);
    }
    private function metadata():array{return ['course_name'=>'Course 中文','lesson_name'=>'Урок','lesson_created_at'=>'2026-10-09T10:00:00Z','source_language'=>'en','target_language'=>'zh','exported_at'=>'2026-10-09T11:00:00Z','media_samples'=>48000];}
}
