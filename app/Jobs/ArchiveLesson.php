<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Access;
use App\Storage\AwsS3OssStorage;
use App\Storage\PcmArchiveAssembler;
use App\Storage\RecordingPaths;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ArchiveLesson implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 7200;

    public function __construct(public string|int $lessonId)
    {
        $this->onQueue('archives');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('archive:'.$this->lessonId))->releaseAfter(30)->expireAfter(7300)];
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AwsS3OssStorage $storage, PcmArchiveAssembler $assembler): void
    {
        $lesson = DB::table('lessons')->where('id', $this->lessonId)->first();
        if ($lesson === null || $lesson->archive_status === 'playable') return;
        if ($lesson->status !== 'ended') throw new RuntimeException('recording_lesson_not_ended');
        $samples = (int) $lesson->media_samples;
        $this->setStatus('processing');
        try {
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
            $assembler->concatenate(DB::table('audio_segments')->where('lesson_id', $this->lessonId)
                ->orderBy('start_sample')->orderBy('id')->cursor(), $samples, $pcm, $directory.'/manifest.ndjson');
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
            // Phase A deliberately retains source segments, PCM and manifest until an
            // explicit retention policy and independently tested backup are configured.
        } catch (\Throwable $error) {
            $this->setStatus('failed');
            Log::warning('recording.archive_failed', ['lesson_id' => $this->lessonId, 'error_type' => get_class($error)]);
            throw new RuntimeException('recording_archive_failed');
        }
    }

    public function failed(?\Throwable $error): void
    {
        $this->setStatus('failed');
    }

    private function setStatus(string $status): void
    {
        DB::transaction(function () use ($status): void {
            $updated = DB::table('lessons')->where('id', $this->lessonId)->update([
                'archive_status' => $status, 'updated_at' => now()]);
            if ($updated !== 0) Access::lessonEvent((int) $this->lessonId);
        });
    }
}
