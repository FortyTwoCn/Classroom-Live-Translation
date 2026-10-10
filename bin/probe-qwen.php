#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Translation\QwenConfig;
use App\Translation\QwenSession;
use Workerman\Timer;
use Workerman\Worker;
use Workerman\Events\Select;

/** Real-only probe: supply speech PCM16LE mono 16 kHz, not WAV/container bytes. */
$report = [
    'test_kind' => 'real_model', 'started_at' => gmdate(DATE_ATOM), 'status' => 'NOT_RUN',
    'checks' => [], 'source_final_count' => 0, 'translation_final_count' => 0,
    'source_delta_count' => 0, 'translation_delta_count' => 0, 'response_status_counts' => [],
    'usage' => [], 'errors' => [], 'transcript_storage' => 'counts_only_no_text',
];
$print = static function (array $data): void {
    fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
};
if (!is_file(__DIR__.'/../vendor/autoload.php')) {
    $report['status'] = 'SKIP';
    $report['reason'] = 'dependencies_not_installed';
    $print($report);
    exit(2);
}
$app = require __DIR__.'/bootstrap.php';
$config = QwenConfig::fromLaravel();
if ($config->missing() !== []) {
    $report['status'] = 'SKIP';
    $report['reason'] = 'configuration_missing';
    $report['missing'] = $config->missing();
    $print($report);
    exit(2);
}
try {
    $config->validate();
    if (in_array(parse_url($config->endpoint, PHP_URL_HOST), ['localhost','127.0.0.1','[::1]'], true)) {
        throw new InvalidArgumentException('REAL_PROBE_REJECTS_TEST_ENDPOINT');
    }
} catch (InvalidArgumentException $error) {
    $report['status'] = 'FAIL';
    $report['reason'] = $error->getMessage();
    $print($report);
    exit(1);
}
$pcmPath = $argv[1] ?? env('QWEN_PROBE_PCM', '');
$language = $argv[2] ?? 'zh';
if (!is_string($pcmPath) || $pcmPath === '' || !is_file($pcmPath) || !is_readable($pcmPath)) {
    $report['status'] = 'SKIP';
    $report['reason'] = 'speech_pcm_file_missing';
    $print($report);
    exit(2);
}
$size = filesize($pcmPath);
if ($size === false || $size < 3200 || $size > 32000 * 120 || ($size % 2) !== 0) {
    $report['status'] = 'FAIL';
    $report['reason'] = 'pcm_size_invalid_use_0_1_to_120_seconds';
    $print($report);
    exit(1);
}
$handle = fopen($pcmPath, 'rb');
$magic = fread($handle, 12);
rewind($handle);
if (substr($magic, 0, 4) === 'RIFF' || substr($magic, 0, 4) === 'OggS' || substr($magic, 4, 4) === 'ftyp' || substr($magic, 0, 4) === "\x1a\x45\xdf\xa3") {
    fclose($handle);
    $report['status'] = 'FAIL';
    $report['reason'] = 'container_bytes_rejected_convert_to_raw_pcm';
    $print($report);
    exit(1);
}
$report += ['model' => $config->model, 'region' => $config->region, 'target_language' => $language,
    'input_samples' => intdiv($size, 2), 'input_sha256' => hash_file('sha256', $pcmPath),
    'device_capture_verified' => false, 'language_quality_verified' => false,
];
$units = [];
$responses = [];
$states = [];
$completed = false;
$sendTimer = null;
$stopping = false;
$session = null;
$loop = new Select();
Worker::$globalEvent = $loop;
Timer::init($loop);
$start = static function () use (&$report, &$units, &$responses, &$states, &$completed, &$sendTimer, &$stopping, &$session, $config, $language, $handle, $size, $loop): void {
    $stop = static function () use (&$stopping, &$sendTimer, $loop): void {
        if ($stopping) {
            return;
        }
        $stopping = true;
        if ($sendTimer !== null) {
            Timer::del($sendTimer);
            $sendTimer = null;
        }
        Timer::add(0.05, static fn () => $loop->stop(), [], false);
    };
    try {
        $session = new QwenSession($config, $language,
            static function (array $event) use (&$report, &$units, &$responses): void {
                if ($event['type'] === 'subtitle.upsert') {
                    $units[$event['unit_id']] = $event;
                    if (($event['provider_event_type'] ?? null) === 'conversation.item.input_audio_transcription.delta') {
                        ++$report['source_delta_count'];
                    } elseif (($event['provider_event_type'] ?? null) === 'response.text.delta') {
                        ++$report['translation_delta_count'];
                    }
                } elseif ($event['type'] === 'usage') {
                    $report['usage'][$event['response_id']] = $event['usage'];
                } elseif ($event['type'] === 'response.status') {
                    $responses[$event['response_id']] = $event['status'];
                } elseif ($event['type'] === 'upstream.error') {
                    $report['errors'][] = ['code' => $event['code'], 'category' => $event['category']];
                }
            },
            static function (string $state, array $details) use (&$report, &$states, &$completed, &$sendTimer, &$session, $handle, $stop): void {
                $states[] = $state;
                if ($state === 'ready') {
                    $report['checks']['text_mode_session_updated'] = true;
                    $sendTimer = Timer::add(0.1, static function () use (&$sendTimer, &$session, &$report, $handle, $stop): void {
                        try {
                            $pcm = fread($handle, 3200);
                            if ($pcm === false) {
                                throw new RuntimeException('PCM_READ_FAILED');
                            }
                            if ($pcm === '') {
                                Timer::del($sendTimer);
                                $sendTimer = null;
                                $session->finish();
                            } else {
                                $session->sendAudio($pcm);
                            }
                        } catch (Throwable) {
                            $report['errors'][] = ['code' => 'audio_send_failed'];
                            $session?->close();
                            $stop();
                        }
                    });
                } elseif ($state === 'finished') {
                    $completed = true;
                    $report['checks']['session_finish_acknowledged'] = true;
                    $stop();
                } elseif (in_array($state, ['failed','closed'], true)) {
                    $report['errors'][] = ['code' => $details['code'] ?? 'closed_before_finish', 'category' => $details['category'] ?? 'upstream'];
                    $stop();
                }
            }
        );
        $session->connect();
        Timer::add(($size / 32000) + $config->connectTimeout + $config->finishTimeout + 5, static function () use (&$report, &$session, $stop): void {
            $report['errors'][] = ['code' => 'probe_total_timeout'];
            $session?->close();
            $stop();
        }, [], false);
    } catch (Throwable) {
        $report['errors'][] = ['code' => 'probe_start_failed'];
        $stop();
    }
};
// A single event loop avoids Linux master/child report copies and gives a meaningful exit code.
$loop->setErrorHandler(static function (Throwable $error) use (&$report, &$session, $loop): void {
    $report['errors'][] = ['code' => 'event_loop_error'];
    $session?->close();
    $loop->stop();
});
$start();
$loop->run();
fclose($handle);
foreach ($units as $unit) {
    if ($unit['status'] === 'completed' && trim($unit['text']) !== '') {
        $field = $unit['kind'] === 'source' ? 'source_final_count' : 'translation_final_count';
        ++$report[$field];
    }
}
$report['response_status_counts'] = array_count_values($responses);
$report['states'] = $states;
$report['checks']['source_nonempty_final'] = $report['source_final_count'] > 0;
$report['checks']['translation_nonempty_final'] = $report['translation_final_count'] > 0;
$report['checks']['source_streaming_observed'] = $report['source_delta_count'] > 0;
$report['checks']['translation_streaming_observed'] = $report['translation_delta_count'] > 0;
$report['checks']['no_failed_or_unfinished_response'] = count(array_filter($responses, static fn ($state) => $state !== 'completed')) === 0;
$report['checks']['no_failed_or_unfinished_unit'] = count(array_filter($units, static fn ($unit) => $unit['status'] !== 'completed')) === 0;
$report['checks']['all_input_sent'] = $session !== null && $session->sentSamples() === intdiv($size, 2);
$report['status'] = $completed && $report['errors'] === [] && !in_array(false, $report['checks'], true) ? 'PASS' : 'FAIL';
if ($completed && !$report['checks']['source_nonempty_final']) {
    $report['reason'] = 'no_recognized_speech_not_a_successful_translation_test';
}
$report['finished_at'] = gmdate(DATE_ATOM);
$print($report);
exit($report['status'] === 'PASS' ? 0 : 1);
