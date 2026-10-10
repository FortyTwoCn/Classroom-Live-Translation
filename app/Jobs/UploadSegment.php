<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Storage\AwsS3OssStorage;
use App\Storage\RecordingPaths;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class UploadSegment implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;
    public int $timeout = 120;

    public function __construct(public int $segmentId)
    {
        $this->onQueue('recordings');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('segment:'.$this->segmentId))->releaseAfter(10)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [5, 15, 45, 120, 300];
    }

    public function handle(AwsS3OssStorage $storage): void
    {
        $segment = DB::table('audio_segments')->where('id', $this->segmentId)->first();
        if ($segment === null || $segment->storage_state === 'uploaded') return;
        if (!hash_equals((string) $segment->storage_version, $storage->configuration->version())) {
            throw new RuntimeException('storage_configuration_version_unavailable');
        }
        DB::table('audio_segments')->where('id', $this->segmentId)->update([
            'storage_state' => 'uploading', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        try {
            $path = RecordingPaths::existing((string) $segment->local_path);
            $key = $segment->object_key ?: $storage->configuration->objectKey('lessons/'.$segment->lesson_id
                .'/segments/'.$segment->epoch.'/'.$segment->sequence.'.pcm');
            $storage->upload($key, $path, 'application/octet-stream', (string) $segment->sha256);
            DB::table('audio_segments')->where('id', $this->segmentId)->update([
                'object_key' => $key, 'storage_state' => 'uploaded', 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('audio_segments')->where('id', $this->segmentId)->update([
                'storage_state' => 'pending', 'updated_at' => now()]);
            Log::warning('recording.segment_upload_failed', ['segment_id' => $this->segmentId,
                'lesson_id' => $segment->lesson_id, 'error_type' => get_class($error)]);
            // AWS exceptions can contain signed URLs and request data. Persist only a stable code.
            throw new RuntimeException('recording_segment_upload_failed');
        }
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('audio_segments')->where('id', $this->segmentId)->update([
            'storage_state' => 'failed', 'updated_at' => now()]);
    }
}
