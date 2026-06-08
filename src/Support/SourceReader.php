<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use function base_path;
use function fclose;
use function fopen;
use function pathinfo;
use function sha1;
use function storage_path;
use function stream_copy_to_stream;
use function uniqid;

/**
 * Resolves a source image to a local, readable path. spatie/image only loads
 * local paths, so a disk-backed original is streamed to a temporary file.
 */
class SourceReader
{
    /**
     * @return array{path: string, temporary: bool}|null null when the source is missing
     */
    public function resolve(string $filepath, ?string $sourceDisk = null): ?array
    {
        if ($sourceDisk !== null && $sourceDisk !== '') {
            $storage = Storage::disk($sourceDisk);

            if (! $storage->exists($filepath)) {
                return null;
            }

            return ['path' => $this->copyToTemp($storage, $filepath), 'temporary' => true];
        }

        $local = base_path($filepath);

        if (! File::exists($local)) {
            return null;
        }

        return ['path' => $local, 'temporary' => false];
    }

    /**
     * Stream a source image off a Laravel disk into a temporary local file.
     */
    protected function copyToTemp(Filesystem $storage, string $filepath): string
    {
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        $tmpPath = storage_path('image-tools/source-' . sha1($filepath) . '-' . uniqid() . ($extension !== '' ? '.' . $extension : ''));

        File::ensureDirectoryExists(\dirname($tmpPath));

        $source = $storage->readStream($filepath);
        $target = fopen($tmpPath, 'w');
        stream_copy_to_stream($source, $target);
        fclose($target);
        if (\is_resource($source)) {
            fclose($source);
        }

        return $tmpPath;
    }
}
