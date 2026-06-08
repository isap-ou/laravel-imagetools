<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Support;

use function array_flip;
use function array_intersect_key;
use function array_pad;
use function config;
use function explode;
use function http_build_query;
use function ksort;
use function parse_str;
use function pathinfo;
use function sha1;
use function substr;

/**
 * Turns a "path?query" (plus an optional source disk) into the canonical seed
 * and the deterministic stored-file destination. Pure and stateless.
 */
class PathResolver
{
    /**
     * Query keys that define the derivative's identity. Anything outside this set
     * is ignored, so asset() (read) and generate() (write) always agree.
     */
    public const OPTION_KEYS = ['w', 'h', 'q', 'fit', 'format'];

    /**
     * Canonical "seed" for a path: the query reduced to OPTION_KEYS and sorted,
     * with the source disk folded in when set (used only as a key/hash input,
     * never parsed back as a path).
     */
    public function seed(string $path, ?string $sourceDisk = null): string
    {
        [$filepath, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        $options = array_intersect_key($options, array_flip(self::OPTION_KEYS));
        ksort($options);

        $seed = empty($options) ? $filepath : $filepath . '?' . http_build_query($options);

        if ($sourceDisk !== null && $sourceDisk !== '') {
            return $sourceDisk . ':' . $seed;
        }

        return $seed;
    }

    /**
     * Deterministic storage destination for a "path?query". Computed purely from
     * the seed (no image is loaded), so it is safe to call before generation —
     * e.g. to return a URL while the real file is still being produced.
     *
     * @return array{path: string, disk: string}
     */
    public function storedFile(string $path, ?string $sourceDisk = null): array
    {
        [$filepath, $params] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($params, $options);

        $options = array_intersect_key($options, array_flip(self::OPTION_KEYS));

        $pathInfo = pathinfo($filepath);

        // Output extension: overridden by 'format', else the source extension.
        $extension = ! empty($options['format'])
            ? $options['format']
            : ($pathInfo['extension'] ?? '');

        $fileName = \sprintf(
            '%s--%s.%s',
            str($pathInfo['filename'])->slug('-')->toString(),
            substr(sha1($this->seed($path, $sourceDisk)), 0, 10),
            $extension
        );

        return [
            'path' => 'image-tools/' . $fileName,
            'disk' => config('image-tools.disk'),
        ];
    }
}
