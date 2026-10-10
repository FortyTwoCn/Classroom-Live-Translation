<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Storage\AwsS3OssStorage;
use App\Storage\RecordingPaths;
use App\Storage\{StorageRegistry,SegmentRecovery};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class UploadSegment implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 6;
    public ?int $retryDeadline = null;
    public int $timeout = 120;

    public function __construct(public int $segmentId)
    {
        $this->retryDeadline=time()+86400;
        $this->onQueue('recordings');
    }

    public function retryUntil(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable)->setTimestamp($this->retryDeadline??time()+86400);
    }

    public function middleware(): array
    {
        $id=DB::table('audio_segments')->where('id',$this->segmentId)->value('lesson_id');
        return [(new WithoutOverlapping('lesson-data:'.$id))->shared()->releaseAfter(10)->expireAfter(7500),
            (new WithoutOverlapping('segment:'.$this->segmentId))->releaseAfter(10)->expireAfter(150)];
    }

    public function backoff(): array
    {
        return [5, 15, 45, 120, 300];
    }

    public function handle(SegmentRecovery $recovery): void
    {
        $segment = DB::table('audio_segments')->where('id', $this->segmentId)->first();
        if ($segment === null || in_array($segment->storage_state,['uploaded','failed'],true)) return;
        $lesson=DB::table('lessons')->find($segment->lesson_id);
        if(!$lesson||$lesson->purge_status!==null)return;
        if($segment->chunk_id){UploadChunk::dispatch((int)$segment->chunk_id);return;}
        DB::table('audio_segments')->where('id', $this->segmentId)->update([
            'storage_state' => 'uploading', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        try {
            $storage=StorageRegistry::resolve($segment->storage_version);
            $path = $recovery->ensure($segment);
            $key = $segment->object_key ?: $storage->configuration->objectKey('lessons/'.$segment->lesson_id
                .'/segments/'.$segment->epoch.'/'.$segment->sequence.'.pcm');
            // Retain ownership before any network side effect, including a
            // successful PUT followed by a process exit before publication.
            DB::table('audio_segments')->where('id',$this->segmentId)->update(['object_key'=>$key,'updated_at'=>now()]);
            $storage->upload($key, $path, 'application/octet-stream', (string) $segment->sha256);
            DB::table('audio_segments')->where('id', $this->segmentId)->update([
                'object_key' => $key, 'storage_state' => 'uploaded', 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('audio_segments')->where('id', $this->segmentId)->update([
                'storage_state' => 'pending', 'updated_at' => now()]);
            Log::warning('recording.segment_upload_failed', ['segment_id' => $this->segmentId,
                'lesson_id' => $segment->lesson_id, 'error_type' => get_class($error)]);
            // AWS exceptions can contain signed URLs and request data. Persist only a stable code.
            throw new RuntimeException($error->getMessage()==='storage_configuration_version_unavailable'?'storage_configuration_version_unavailable':'recording_segment_upload_failed');
        }
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('audio_segments')->where('id', $this->segmentId)->where('storage_state','<>','uploaded')->update([
            'storage_state' => 'failed', 'updated_at' => now()]);
    }
}
