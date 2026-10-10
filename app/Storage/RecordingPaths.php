<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

final class RecordingPaths
{
    public static function existing(string $path): string
    {
        $root = realpath(storage_path('app/recordings'));
        $resolved = realpath($path);
        if ($resolved === false && !str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path)) {
            $resolved = realpath(storage_path('app/'.$path));
        }
        if ($root === false || $resolved === false || !is_file($resolved)) {
            throw new RuntimeException('recording_input_missing');
        }
        $normalize = static fn (string $value): string => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $value)) : str_replace('\\', '/', $value);
        if (!str_starts_with($normalize($resolved), $normalize($root).'/')) {
            throw new RuntimeException('recording_path_not_allowed');
        }
        return $resolved;
    }

    public static function archiveDirectory(string|int $lessonId): string
    {
        if (!preg_match('/^[a-zA-Z0-9-]+$/D', (string) $lessonId)) {
            throw new RuntimeException('recording_lesson_id_invalid');
        }
        $path = storage_path('app/recordings/'.$lessonId.'/archive');
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('recording_directory_failed');
        }
        $root = realpath(storage_path('app/recordings'));
        $actual = realpath($path);
        $normalize = static fn (string $value): string => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('\\', '/', $value)) : str_replace('\\', '/', $value);
        if ($root === false || $actual === false || !str_starts_with($normalize($actual), $normalize($root).'/')) {
            throw new RuntimeException('recording_path_not_allowed');
        }
        return $actual;
    }
}
