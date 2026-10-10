<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\PcmArchiveAssembler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StorageArchiveTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        restore_error_handler();
        restore_exception_handler();
        $this->directory = storage_path('app/recordings/test-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) if (is_file($path)) unlink($path);
        rmdir($this->directory);
        parent::tearDown();
    }

    private function segment(int $sequence, int $start, string $audio): array
    {
        $path = $this->directory.'/'.$sequence.'.pcm';
        file_put_contents($path, $audio);
        return ['id' => $sequence, 'epoch' => 1, 'sequence' => $sequence, 'start_sample' => $start,
            'sample_count' => intdiv(strlen($audio), 2), 'sha256' => hash('sha256', $audio), 'local_path' => $path,
            'object_key' => null, 'storage_version' => 'test'];
    }

    public function test_exact_pcm_survives_epoch_changes_without_pause_time(): void
    {
        $first = $this->segment(1, 0, str_repeat(pack('v', 1234), 1600));
        $second = $this->segment(2, 1600, str_repeat(pack('v', 4321), 1600));
        $second['epoch'] = 3;
        $out = $this->directory.'/input.pcm';
        $manifest = $this->directory.'/manifest.ndjson';
        (new PcmArchiveAssembler())->concatenate([$first, $second], 3200, $out, $manifest);
        self::assertSame(file_get_contents($first['local_path']).file_get_contents($second['local_path']), file_get_contents($out));
        self::assertCount(2, file($manifest, FILE_IGNORE_NEW_LINES));
        self::assertSame(6400, filesize($out));
    }

    public function test_missing_media_interval_prevents_false_playable_archive(): void
    {
        $segment = $this->segment(1, 10, str_repeat("\0", 3200));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recording_timeline_gap_or_overlap');
        (new PcmArchiveAssembler())->concatenate([$segment], 1600, $this->directory.'/input.pcm', $this->directory.'/manifest.ndjson');
    }

    public function test_corrupt_input_prevents_archive(): void
    {
        $segment = $this->segment(1, 0, str_repeat("\0", 3200));
        file_put_contents($segment['local_path'], str_repeat("\1", 3200));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recording_segment_checksum_failed');
        (new PcmArchiveAssembler())->concatenate([$segment], 1600, $this->directory.'/input.pcm', $this->directory.'/manifest.ndjson');
    }

    public function test_manifest_must_account_for_every_received_sample(): void
    {
        $segment = $this->segment(1, 0, str_repeat("\0", 3200));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recording_timeline_length_mismatch');
        (new PcmArchiveAssembler())->concatenate([$segment], 3200, $this->directory.'/input.pcm', $this->directory.'/manifest.ndjson');
    }

    public function test_actual_ffmpeg_encodes_and_decodes_fixture_when_installed(): void
    {
        $ffmpeg = config('classroom.ffmpeg');
        $ffprobe = config('classroom.ffprobe');
        if (!is_file($ffmpeg) || !is_file($ffprobe)) self::markTestSkipped('Real FFmpeg/FFprobe paths not configured. Synthetic PCM test; no real device or OSS.');
        $audio = '';
        for ($i = 0; $i < 32000; $i++) $audio .= pack('v', (int) round(7000 * sin(2 * M_PI * 440 * $i / 16000)) & 0xffff);
        $pcm = $this->directory.'/source.pcm';
        file_put_contents($pcm, $audio);
        $result = (new PcmArchiveAssembler())->encode($pcm, $this->directory.'/original.m4a', 32000);
        self::assertSame('audio/mp4', $result['mime']);
        self::assertSame(32000, $result['duration_samples']);
        self::assertGreaterThan(1000, $result['bytes']);
    }
}
