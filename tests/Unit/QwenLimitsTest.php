<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Translation\{QwenConfig,QwenEvents};
use PHPUnit\Framework\TestCase;

final class QwenLimitsTest extends TestCase
{
    public function test_total_update_cap_includes_event_id_and_utf8_bytes():void
    {
        $phrases=[];for($i=0;$i<300;$i++)$phrases[str_repeat('s',232).sprintf('%04d',$i)]=str_repeat('译',4);
        $this->expectExceptionMessage('QWEN_LOCAL_PAYLOAD_LIMIT');QwenConfig::fromArray(['phrases'=>$phrases])->sessionUpdate('zh');
    }
    public function test_numeric_sources_are_encoded_as_an_object_without_changing_words():void
    {
        $config=QwenConfig::fromArray(['phrases'=>['123'=>'一二三','airfoil'=>'翼型']]);$update=$config->sessionUpdate('zh');
        self::assertIsObject($update['session']['translation']['corpus']['phrases']);self::assertStringContainsString('"123":"一二三"',json_encode($update,JSON_UNESCAPED_UNICODE));
        self::assertSame(strlen(json_encode($update+['event_id'=>'evt_'.str_repeat('0',24)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),QwenConfig::updateBytes($update));
    }
    public function test_invalid_utf8_or_control_characters_never_reach_provider():void
    {
        foreach([['word'=>"\xff"],["line\nword"=>'词']]as $phrases){try{QwenConfig::fromArray(['phrases'=>$phrases])->sessionUpdate('zh');self::fail('Invalid phrase accepted.');}catch(\InvalidArgumentException $e){self::assertSame('QWEN_LOCAL_PHRASE_INVALID',$e->getMessage());}}
    }
    public function test_observed_speech_and_pending_responses_hold_rotation_boundary():void
    {
        $events=new QwenEvents('zh');self::assertFalse($events->hasPendingWork());
        $events->normalize(['type'=>'input_audio_buffer.speech_started','item_id'=>'s','audio_start_ms'=>0]);self::assertTrue($events->hasPendingWork());
        $events->normalize(['type'=>'input_audio_buffer.speech_stopped','item_id'=>'s','audio_end_ms'=>100]);self::assertTrue($events->hasPendingWork());
        $events->normalize(['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'s','transcript'=>'airfoil']);self::assertFalse($events->hasPendingWork());
        $events->normalize(['type'=>'response.created','response'=>['id'=>'r']]);self::assertTrue($events->hasPendingWork());
        $events->normalize(['type'=>'response.text.done','response_id'=>'r','item_id'=>'t','text'=>'翼型']);self::assertTrue($events->hasPendingWork());
        $events->normalize(['type'=>'response.done','response'=>['id'=>'r','status'=>'completed','output'=>[]]]);self::assertFalse($events->hasPendingWork());
        // A delayed stop cannot reopen already completed ASR work.
        $events->normalize(['type'=>'input_audio_buffer.speech_stopped','item_id'=>'s','audio_end_ms'=>100]);self::assertFalse($events->hasPendingWork());
    }
    public function test_pending_response_limit_never_forgets_work_to_report_a_quiet_boundary():void
    {
        $events=new QwenEvents;for($i=0;$i<4096;$i++)$events->normalize(['type'=>'response.created','response'=>['id'=>'pending_'.$i]]);
        self::assertTrue($events->hasPendingWork());$this->expectExceptionMessage('QWEN_TOO_MANY_PENDING_RESPONSES');$events->normalize(['type'=>'response.created','response'=>['id'=>'overflow']]);
    }
    public function test_conservative_source_and_target_byte_caps_allow_realistic_ukrainian_terms():void
    {
        $phrases=['airfoil'=>'аеродинамічний профіль'];self::assertNotEmpty(QwenConfig::fromArray(['phrases'=>$phrases])->sessionUpdate('uk'));
        foreach([[str_repeat('a',257)=>'词'],['word'=>str_repeat('a',65)]]as $map){try{QwenConfig::fromArray(['phrases'=>$map])->sessionUpdate('zh');self::fail('Application byte cap bypassed.');}catch(\InvalidArgumentException $e){self::assertSame('QWEN_LOCAL_PHRASE_INVALID',$e->getMessage());}}
    }
}
