<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;
use Symfony\Component\Process\Process;

final class PcmArchiveAssembler
{
    public const SAMPLE_RATE = 16000;

    /** Exact PCM timeline; missing/corrupt audio fails instead of inventing continuity. */
    public function concatenate(iterable $segments, int $expectedSamples, string $destination, string $manifest): void
    {
        if ($expectedSamples <= 0) {
            throw new RuntimeException('recording_no_audio');
        }
        $output = fopen($destination, 'wb');
        $index = fopen($manifest, 'wb');
        if ($output === false || $index === false) {
            if (is_resource($output)) fclose($output);
            if (is_resource($index)) fclose($index);
            throw new RuntimeException('recording_output_failed');
        }
        $samples = 0;
        try {
            foreach ($segments as $segment) {
                $segment = (array) $segment;
                $count = (int) $segment['sample_count'];
                if ((int) $segment['start_sample'] !== $samples || $count <= 0) {
                    throw new RuntimeException('recording_timeline_gap_or_overlap');
                }
                $path = RecordingPaths::existing((string) $segment['local_path']);
                if (filesize($path) !== $count * 2
                    || !hash_equals((string) $segment['sha256'], hash_file('sha256', $path))) {
                    throw new RuntimeException('recording_segment_checksum_failed');
                }
                $input = fopen($path, 'rb');
                if ($input === false) {
                    throw new RuntimeException('recording_input_missing');
                }
                try {
                    if (stream_copy_to_stream($input, $output) !== $count * 2) {
                        throw new RuntimeException('recording_output_write_failed');
                    }
                } finally {
                    fclose($input);
                }
                $entry = array_intersect_key($segment, array_flip(['id', 'epoch', 'sequence', 'start_sample',
                    'sample_count', 'sha256', 'object_key', 'storage_version']));
                if (fwrite($index, json_encode($entry, JSON_THROW_ON_ERROR)."\n") === false) {
                    throw new RuntimeException('recording_manifest_write_failed');
                }
                $samples += $count;
            }
            if ($samples !== $expectedSamples) {
                throw new RuntimeException('recording_timeline_length_mismatch');
            }
            if (!fflush($output) || !fflush($index) || !fsync($output) || !fsync($index)) {
                throw new RuntimeException('recording_output_sync_failed');
            }
        } finally {
            fclose($output);
            fclose($index);
        }
    }

    public function encode(string $pcm, string $output, int $samples): array
    {
        $process = new Process([(string) config('classroom.ffmpeg', 'ffmpeg'), '-hide_banner', '-loglevel', 'error',
            '-nostdin', '-y', '-f', 's16le', '-ar', (string) self::SAMPLE_RATE, '-ac', '1', '-i', $pcm,
            '-vn', '-c:a', 'aac', '-b:a', '64k', '-movflags', '+faststart', '-f', 'ipod', $output]);
        $process->setTimeout(7200);
        $process->run();
        if (!$process->isSuccessful() || !is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('recording_transcode_failed');
        }
        $probe = new Process([(string) config('classroom.ffprobe', 'ffprobe'), '-v', 'error', '-show_streams',
            '-show_format', '-of', 'json', $output]);
        $probe->setTimeout(60);
        $probe->run();
        if (!$probe->isSuccessful()) {
            throw new RuntimeException('recording_probe_failed');
        }
        $info = json_decode($probe->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        $streams = $info['streams'] ?? [];
        $duration = (float) ($info['format']['duration'] ?? 0);
        if (count($streams) !== 1 || ($streams[0]['codec_name'] ?? '') !== 'aac'
            || (int) ($streams[0]['channels'] ?? 0) !== 1
            || (int) ($streams[0]['sample_rate'] ?? 0) !== self::SAMPLE_RATE
            || abs($duration - $samples / self::SAMPLE_RATE) > 0.15) {
            throw new RuntimeException('recording_media_validation_failed');
        }
        // Decode the complete result: a valid header alone does not prove a playable file.
        $decode = new Process([(string) config('classroom.ffmpeg', 'ffmpeg'), '-hide_banner', '-loglevel', 'error',
            '-nostdin', '-i', $output, '-vn', '-f', 'null', '-']);
        $decode->setTimeout(7200);
        $decode->run();
        if (!$decode->isSuccessful()) {
            throw new RuntimeException('recording_decode_validation_failed');
        }
        return ['bytes' => (int) filesize($output), 'sha256' => hash_file('sha256', $output),
            'duration_samples' => $samples, 'mime' => 'audio/mp4', 'measured_seconds' => $duration];
    }
}
