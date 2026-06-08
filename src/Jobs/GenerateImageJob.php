<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

use function app;
use function config;

/**
 * Queued generation of a single ImageTools derivative.
 *
 * Dispatched by ImageTools::asset() when a request opts into deferred mode via
 * a truthy "queue" query flag. Implements ShouldBeUnique (keyed by the canonical
 * seed) so that many concurrent page renders referencing the same, not-yet-
 * generated image coalesce into a single job instead of a storm of duplicates.
 */
class GenerateImageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $path,
        public string $manifest = 'default',
        public ?string $sourceDisk = null,
    ) {
        $this->onConnection(config('image-tools.queue_connection'));
        $this->onQueue(config('image-tools.queue_name'));
    }

    public function handle(): void
    {
        $imageTools = app('image-tools');

        if ($this->sourceDisk !== null && $this->sourceDisk !== '') {
            $imageTools = $imageTools->disk($this->sourceDisk);
        }

        $imageTools->generate($this->path, $this->manifest);
    }

    /**
     * Unique by the canonical seed (path + source disk): the same derivative is
     * never queued twice while a prior job for it is still pending.
     */
    public function uniqueId(): string
    {
        return ($this->sourceDisk ?? '') . ':' . $this->path;
    }

    /**
     * Seconds the uniqueness lock is held (mirrors config('image-tools.unique_for')).
     */
    public function uniqueFor(): int
    {
        return (int) config('image-tools.unique_for', 3600);
    }
}
