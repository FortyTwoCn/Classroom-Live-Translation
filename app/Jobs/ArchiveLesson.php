<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Access;
use App\Storage\AwsS3OssStorage;
use App\Storage\PcmArchiveAssembler;
use App\Storage\RecordingPaths;
use App\Storage\{StorageRegistry,SegmentRecovery,ChunkPlanner};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ArchiveLesson implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;
    public int $maxExceptions = 4;
    public ?int $retryDeadline = null;
    public int $timeout = 7200;
    public bool $force=false;

    public function __construct(public string|int $lessonId,bool $force=false)
    {
        $this->force=$force;
        $this->retryDeadline=time()+86400;
        $this->onQueue('archives');
    }

    public function retryUntil(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable)->setTimestamp($this->retryDeadline??time()+86400);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('lesson-data:'.$this->lessonId))->shared()->releaseAfter(10)->expireAfter(7500),
            (new WithoutOverlapping('archive:'.$this->lessonId))->releaseAfter(30)->expireAfter(7300)];
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(PcmArchiveAssembler $assembler,SegmentRecovery $recovery): void
    {
        $lesson = DB::table('lessons')->where('id', $this->lessonId)->first();
        if($lesson!==null&&$lesson->purge_status!==null)return;
        if ($lesson === null || (in_array($lesson->archive_status,['playable','failed'],true)&&!$this->force)) return;
        if ($lesson->status !== 'ended') throw new RuntimeException('recording_lesson_not_ended');
        $samples = (int) $lesson->media_samples;
        $this->setStatus('processing');
        try {
            ChunkPlanner::flushLesson((int)$this->lessonId);
            $storage=StorageRegistry::resolve(StorageRegistry::lessonVersion($lesson));
            $differentVersion = DB::table('audio_segments')->where('lesson_id', $this->lessonId)
                ->where('storage_version', '!=', $storage->configuration->version())->exists();
            if ($differentVersion) throw new RuntimeException('storage_configuration_version_unavailable');
            $directory = RecordingPaths::archiveDirectory($this->lessonId);
            $free = disk_free_space($directory);
            if ($free === false || $free < $samples * 4 + (int) config('classroom.min_disk_bytes', 536870912)) {
                throw new RuntimeException('recording_archive_disk_capacity');
            }
            $pcm = $directory.'/input.pcm';
            $media = $directory.'/original.m4a';
            $segments=DB::table('audio_segments')->where('lesson_id',$this->lessonId)->orderBy('start_sample')->orderBy('id')->cursor()->map(function($s)use($recovery){$s->local_path=$recovery->ensure($s);return $s;});
            $assembler->concatenate($segments, $samples, $pcm, $directory.'/manifest.ndjson');
            $result = $assembler->encode($pcm, $media, $samples);
            $key = $storage->configuration->objectKey('lessons/'.$this->lessonId.'/archive/original.m4a');
            $storage->upload($key, $media, $result['mime'], $result['sha256']);
            DB::transaction(function () use ($key, $result, $storage): void {
                DB::table('recordings')->updateOrInsert(['lesson_id' => $this->lessonId], [
                    'object_key' => $key, 'bytes' => $result['bytes'], 'sha256' => $result['sha256'],
                    'duration_samples' => $result['duration_samples'], 'mime' => $result['mime'],
                    'status' => 'playable', 'storage_version' => $storage->configuration->version(),
                    'created_at' => now(), 'updated_at' => now()]);
                DB::table('lessons')->where('id', $this->lessonId)->update([
                    'archive_status' => 'playable', 'updated_at' => now()]);
                Access::lessonEvent((int) $this->lessonId);
            });
            // Source PCM, lossless chunks and manifests remain recoverable until an
            // explicitly configured and audited retention policy removes them.
        } catch (\Throwable $error) {
            $this->setStatus('failed');
            Log::warning('recording.archive_failed', ['lesson_id' => $this->lessonId, 'error_type' => get_class($error)]);
            throw new RuntimeException($error->getMessage()==='storage_configuration_version_unavailable'?'storage_configuration_version_unavailable':'recording_archive_failed');
        }
    }

    public function failed(?\Throwable $error): void
    {
        $this->setStatus('failed');
    }

    private function setStatus(string $status): void
    {
        DB::transaction(function () use ($status): void {
            $updated = DB::table('lessons')->where('id', $this->lessonId)->whereNull('purge_status')->update([
                'archive_status' => $status, 'updated_at' => now()]);
            if ($updated !== 0) Access::lessonEvent((int) $this->lessonId);
        });
    }
}
