<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Translation\QwenConfig;
use App\Translation\QwenEvents;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QwenEventsTest extends TestCase
{
    public function test_source_and_translation_have_distinct_ids_and_late_explicit_mapping(): void
    {
        $parser = new QwenEvents('zh');
        $source = $parser->normalize(['type'=>'conversation.item.input_audio_transcription.completed','event_id'=>'e1','item_id'=>'asr_1','transcript'=>'Lift coefficient.','language'=>'en'])[0];
        $translated = $parser->normalize(['type'=>'response.text.delta','event_id'=>'e2','item_id'=>'tr_9','response_id'=>'r1','delta'=>'升力系数。'])[0];
        self::assertNotSame($source['unit_id'], $translated['unit_id']);
        self::assertNull($translated['source_item_id']);
        $mapped = $parser->normalize(['type'=>'conversation.item.created','event_id'=>'e3','previous_item_id'=>'asr_1','item'=>['id'=>'tr_9','role'=>'assistant']])[0];
        self::assertSame('asr_1', $mapped['source_item_id']);
        self::assertSame('explicit_previous_item_id', $mapped['mapping']);
        self::assertSame('升力系数。', $mapped['text']);
    }

    public function test_equal_ids_do_not_imply_a_bilingual_mapping(): void
    {
        $parser = new QwenEvents();
        $parser->normalize(['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'same','transcript'=>'hello']);
        $unit = $parser->normalize(['type'=>'response.text.delta','item_id'=>'same','response_id'=>'r','delta'=>'你好'])[0];
        self::assertNull($unit['source_item_id']);
        self::assertSame('unavailable', $unit['mapping']);
    }

    public function test_timing_is_only_from_provider_audio_offsets_and_inherited_by_explicit_mapping(): void
    {
        $parser = new QwenEvents();
        $parser->normalize(['type'=>'input_audio_buffer.speech_started','item_id'=>'asr','audio_start_ms'=>568]);
        $parser->normalize(['type'=>'input_audio_buffer.speech_stopped','item_id'=>'asr','audio_end_ms'=>3900]);
        $parser->normalize(['type'=>'conversation.item.created','previous_item_id'=>'asr','item'=>['id'=>'tr','role'=>'assistant']]);
        $unit = $parser->normalize(['type'=>'response.text.delta','item_id'=>'tr','response_id'=>'r','delta'=>'text'])[0];
        self::assertSame(568, $unit['start_ms']);
        self::assertSame(3900, $unit['end_ms']);
        self::assertSame('server_segment', $unit['timing_precision']);
        $unknown = $parser->normalize(['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'another','transcript'=>'unknown'])[0];
        self::assertNull($unknown['start_ms']);
        self::assertNull($unknown['end_ms']);
        self::assertSame('unavailable', $unknown['timing_precision']);
    }

    #[DataProvider('terminalStatuses')]
    public function test_text_done_does_not_mean_success(string $providerStatus, string $status): void
    {
        $parser = new QwenEvents();
        $unit = $parser->normalize(['type'=>'response.text.done','item_id'=>'tr','response_id'=>'r','text'=>'unfinished'])[0];
        self::assertSame('text_done', $unit['status']);
        $events = $parser->normalize(['type'=>'response.done','response'=>['id'=>'r','status'=>$providerStatus,'output'=>[],'usage'=>['input_tokens'=>7]]]);
        self::assertSame($status, $events[0]['status']);
        self::assertSame('usage', $events[2]['type']);
        self::assertSame(['input_tokens'=>7], $events[2]['usage']);
    }

    public static function terminalStatuses(): array
    {
        return [['completed','completed'],['failed','failed'],['incomplete','incomplete'],['cancelled','interrupted']];
    }

    public function test_response_done_can_supply_text_without_a_text_done_event(): void
    {
        $parser = new QwenEvents('uk');
        $event = ['type'=>'response.done','event_id'=>'done','response'=>['id'=>'r','status'=>'completed','output'=>[['id'=>'tr','role'=>'assistant','status'=>'completed','content'=>[['type'=>'text','text'=>'І Ї Є Ґ — клас.']]]]]];
        $unit = $parser->normalize($event)[0];
        self::assertSame('І Ї Є Ґ — клас.', $unit['text']);
        self::assertSame('completed', $unit['status']);
        self::assertSame('uk', $unit['language']);
        self::assertSame([], $parser->normalize($event));
    }

    public function test_out_of_order_text_done_keeps_failed_response_status(): void
    {
        $parser = new QwenEvents();
        $parser->normalize(['type'=>'response.done','response'=>['id'=>'r','status'=>'failed','output'=>[]]]);
        $unit = $parser->normalize(['type'=>'response.text.done','item_id'=>'tr','response_id'=>'r','text'=>'partial'])[0];
        self::assertSame('failed', $unit['status']);
    }

    public function test_duplicate_delta_is_not_appended_and_late_delta_does_not_mutate_final_text(): void
    {
        $parser = new QwenEvents();
        $delta = ['type'=>'conversation.item.input_audio_transcription.delta','event_id'=>'e1','item_id'=>'asr','delta'=>'one'];
        $parser->normalize($delta);
        self::assertSame([], $parser->normalize($delta));
        $unit = $parser->normalize(['type'=>'conversation.item.input_audio_transcription.completed','event_id'=>'e2','item_id'=>'asr','transcript'=>'one.'])[0];
        self::assertSame('one.', $unit['text']);
        $delta['event_id'] = 'e3';
        $unit = $parser->normalize($delta)[0];
        self::assertSame('one.', $unit['text']);
        self::assertSame('completed', $unit['status']);
    }

    public function test_disconnect_marks_only_unconfirmed_fields_interrupted(): void
    {
        $parser = new QwenEvents();
        $parser->normalize(['type'=>'conversation.item.input_audio_transcription.completed','item_id'=>'asr','transcript'=>'confirmed']);
        $parser->normalize(['type'=>'response.text.done','item_id'=>'tr','response_id'=>'r','text'=>'not confirmed']);
        $events = $parser->interrupt('session_finish_timeout');
        self::assertCount(1, $events);
        self::assertSame('translation', $events[0]['kind']);
        self::assertSame('interrupted', $events[0]['status']);
        self::assertSame('not confirmed', $events[0]['text']);
    }

    public function test_source_failure_is_separate_and_legacy_deltas_are_not_interpreted(): void
    {
        $parser = new QwenEvents();
        self::assertSame([], $parser->normalize(['type'=>'response.text.text','text'=>'legacy','stash'=>'old']));
        $event = $parser->normalize(['type'=>'conversation.item.input_audio_transcription.failed','item_id'=>'asr','error'=>['code'=>'invalid_audio']])[0];
        self::assertSame('failed', $event['status']);
        self::assertSame('invalid_audio', $event['error_code']);
        self::assertSame('input', QwenEvents::errorCategory('invalid_audio'));
    }

    public function test_config_uses_the_38_text_fields_and_does_not_force_source_language(): void
    {
        $config = $this->config();
        self::assertSame(['type'=>'session.update','session'=>['output_modalities'=>['text'],'translation'=>['language'=>'uk']]], $config->sessionUpdate('uk'));
        self::assertSame('wss://workspace.cn-beijing.maas.aliyuncs.com/api-ws/v1/realtime?model=qwen3.8-livetranslate-flash-realtime', $config->connectionUrl());
    }

    public function test_endpoint_must_match_workspace_region_and_reject_private_destinations(): void
    {
        $config = $this->config(['endpoint'=>'wss://127.0.0.1/api-ws/v1/realtime']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('QWEN_ENDPOINT_REGION_WORKSPACE_MISMATCH');
        $config->validate();
    }

    public function test_empty_endpoint_uses_only_the_official_regional_workspace_host(): void
    {
        $config = $this->config(['endpoint'=>'']);
        $config->validate();
        self::assertSame('wss://workspace.cn-beijing.maas.aliyuncs.com/api-ws/v1/realtime', $config->endpoint);
        self::assertSame([], $config->missing());
    }

    public function test_test_endpoint_requires_explicit_flag_and_test_environment(): void
    {
        $values = ['endpoint'=>'ws://127.0.0.1:8089/test','allow_test_endpoint'=>true];
        $this->config($values, true)->validate();
        $this->expectException(InvalidArgumentException::class);
        $this->config($values, false)->validate();
    }

    public function test_header_injection_in_credentials_is_rejected(): void
    {
        $config = $this->config(['api_key'=>"key\r\nHost: secret"]);
        $this->expectExceptionMessage('QWEN_CREDENTIAL_INVALID');
        $config->validate();
    }

    private function config(array $overrides = [], bool $testing = false): QwenConfig
    {
        return QwenConfig::fromArray($overrides + [
            'model'=>'qwen3.8-livetranslate-flash-realtime','region'=>'cn-beijing','workspace_id'=>'workspace',
            'endpoint'=>'wss://workspace.cn-beijing.maas.aliyuncs.com/api-ws/v1/realtime','api_key'=>'test-key-never-sent',
        ], $testing);
    }
}
