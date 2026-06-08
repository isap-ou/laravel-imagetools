<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

use function app;
use function var_export;

/**
 * The PHP manifest mapping a canonical seed to its stored file information,
 * keyed by namespace. Owns both the in-memory state and its persistence.
 */
class Manifest
{
    /** @var array<string, array<string, array{path: string, disk: string}>> */
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

    public function has(string $namespace, string $key): bool
    {
        return isset($this->namespaces[$namespace][$key]);
    }

    /**
     * @return array{path: string, disk: string}|null
     */
    public function get(string $namespace, string $key): ?array
    {
        return $this->namespaces[$namespace][$key] ?? null;
    }

    /**
     * Record a seed -> stored-file mapping and persist the manifest to disk.
     * Opcache is invalidated to ensure fresh reads after deployment.
     *
     * @param  array{path: string, disk: string}  $info
     */
    public function put(string $namespace, string $key, array $info): void
    {
        $this->namespaces[$namespace][$key] = $info;

        $contents = "<?php\n\nreturn " . var_export($this->namespaces[$namespace], true) . ";\n";

        app(Filesystem::class)->replace($this->path, $contents);

        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }
}
