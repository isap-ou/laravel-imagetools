<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function array_filter;
use function array_map;
use function asset;
use function base_path;
use function explode;
use function fclose;
use function fopen;
use function implode;
use function pathinfo;
use function public_path;
use function rawurldecode;
use function rawurlencode;
use function rtrim;
use function sha1;
use function storage_path;
use function str_replace;
use function str_starts_with;
use function stream_copy_to_stream;
use function substr;
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
     * The public URL of a source (the queued-miss fallback), or null when it has
     * none: a path with a ".." segment, a disk that cannot make URLs, or a local
     * path that is not a file under the public directory. A disk file is not
     * checked, because that costs one call to the disk on every render.
     */
    public function publicUrl(string $filepath, ?string $sourceDisk = null): ?string
    {
        // A browser also reads "%2e%2e" and "\" as a parent segment.
        if (\in_array('..', explode('/', str_replace('\\', '/', rawurldecode($filepath))), true)) {
            return null;
        }

        if ($sourceDisk !== null && $sourceDisk !== '') {
            try {
                return Storage::disk($sourceDisk)->url($filepath);
            } catch (RuntimeException) {
                return null;
            }
        }

        $public = str_replace('\\', '/', rtrim(public_path(), '/\\')) . '/';
        $local = str_replace('\\', '/', base_path($filepath));

        if (! str_starts_with($local, $public) || ! File::isFile($local)) {
            return null;
        }

        // A leading "//" would name another host; a space would end a srcset candidate.
        $segments = array_filter(explode('/', substr($local, \strlen($public))), fn (string $segment) => $segment !== '');

        return asset(implode('/', array_map(rawurlencode(...), $segments)));
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
