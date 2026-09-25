<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

use function app;
use function clearstatcache;
use function fclose;
use function flock;
use function fopen;
use function realpath;
use function var_export;

/**
 * The PHP manifest mapping a canonical seed to its stored file information,
 * keyed by namespace. Owns both the in-memory state and its persistence.
 */
class Manifest
{
    /** @var array<string, array<string, array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}>> */
    protected array $namespaces = ['default' => []];

    public function __construct(protected string $path)
    {
        $this->load();
    }

    /**
     * Load a manifest into memory.
     * - When $path is provided, load that file (if it exists) under its own key.
     * - Otherwise, load the default manifest file into the 'default' namespace.
     */
    public function load(?string $path = null): void
    {
        if (! empty($path)) {
            if (File::exists($path)) {
                $this->namespaces[$path] = require $path;
            }

            return;
        }

        if (! File::exists($this->path)) {
            return;
        }

        $this->namespaces['default'] = require $this->path;
    }

    public function exists(string $namespace): bool
    {
        return isset($this->namespaces[$namespace]);
    }

    /**
     * Every entry of a namespace, keyed by canonical seed.
     *
     * @return array<string, array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}>
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->namespaces[$namespace] ?? [];
    }

    public function has(string $namespace, string $key): bool
    {
        return isset($this->namespaces[$namespace][$key]);
    }

    /**
     * @return array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}|null
     */
    public function get(string $namespace, string $key): ?array
    {
        return $this->namespaces[$namespace][$key] ?? null;
    }

    /**
     * Record a seed -> stored-file mapping and persist the manifest to disk.
     * Opcache is invalidated to ensure fresh reads after deployment.
     *
     * Writers take turns under a lock, and each one starts from the file as it
     * is now. A long-lived process (a queue worker holds the singleton between
     * jobs) would otherwise write its old copy back over entries that another
     * process changed since — for example, an entry to a file that was deleted.
     *
     * @param  array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}  $info
     */
    public function put(string $namespace, string $key, array $info): void
    {
        $path = $this->pathFor($namespace);
        $lock = $this->lock($path);

        try {
            $this->namespaces[$namespace] = $this->read($path);
            $this->namespaces[$namespace][$key] = $info;

            $contents = "<?php\n\nreturn " . var_export($this->namespaces[$namespace], true) . ";\n";

            app(Filesystem::class)->replace($path, $contents);

            if (\function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Read the default manifest and delete its file under the write lock, so no
     * writer can put the old entries back in between. Returns the entries the
     * file held.
     *
     * @return array<string, array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}>
     */
    public function clear(): array
    {
        $lock = $this->lock($this->path);

        try {
            $entries = $this->read($this->path);

            File::delete($this->path);

            if (\function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->path, true);
            }

            $this->namespaces['default'] = [];

            return $entries;
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Take the exclusive write lock of a manifest file. The lock file sits next
     * to the real file, so releases that share a symlinked manifest share the
     * lock too. flock() also works on a read-only handle, so a lock file that
     * another user created (a deploy that ran as root) still locks. When no lock
     * can be taken, the write goes ahead without it, as it did before the lock
     * existed: the lock must never make a write fail.
     *
     * @return resource|null
     */
    protected function lock(string $path)
    {
        clearstatcache(true, $path);

        $lockPath = (realpath($path) ?: $path) . '.lock';

        $handle = @fopen($lockPath, 'c') ?: @fopen($lockPath, 'r');

        if ($handle !== false && flock($handle, LOCK_EX)) {
            return $handle;
        }

        if ($handle !== false) {
            fclose($handle);
        }

        Log::warning('ImageTools: the manifest lock could not be taken; the manifest is written without it.', [
            'lock' => $lockPath,
        ]);

        return null;
    }

    /**
     * @param  resource|null  $handle
     */
    protected function unlock($handle): void
    {
        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * The file behind a namespace: the default manifest, or the path that
     * load($path) used as the namespace key.
     */
    protected function pathFor(string $namespace): string
    {
        return $namespace === 'default' ? $this->path : $namespace;
    }

    /**
     * The entries of a manifest file as they are on disk now. The opcache copy
     * is dropped first: when opcache does not check timestamps, it still holds
     * the version this process compiled earlier.
     *
     * @return array<string, array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}>
     */
    protected function read(string $path): array
    {
        clearstatcache(true, $path);

        if (! File::exists($path)) {
            return [];
        }

        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        return require $path;
    }
}
