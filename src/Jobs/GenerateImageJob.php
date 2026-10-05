<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Isapp\ImageTools\Support\PathResolver;

use function app;
use function config;
use function sha1;

/**
 * Queued generation of a single ImageTools derivative.
 *
 * Dispatched by ImageTools::asset() for a miss in deferred mode (the "queue" flag
 * or config). Implements ShouldBeUnique (keyed by the canonical
 * seed) so that many concurrent page renders referencing the same, not-yet-
 * generated image coalesce into a single job instead of a storm of duplicates.
 */
class GenerateImageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
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

        // Another job or a request may have made the entry since this job was queued.
        if ($imageTools->has($this->path, $this->manifest)) {
            return;
        }

        if ($imageTools->generate($this->path, $this->manifest) === null) {
            Log::warning('ImageTools: the queued generation produced no file.', [
                'path' => $this->path,
                'disk' => $this->sourceDisk,
            ]);
        }
    }

    /**
     * Unique by manifest namespace and canonical seed. Hashed, because a long
     * seed would not fit a database cache store's 255-character lock key.
     */
    public function uniqueId(): string
    {
        return sha1($this->manifest . '|' . app(PathResolver::class)->seed($this->path, $this->sourceDisk));
    }

    /**
     * Seconds the uniqueness lock is held (config('image-tools.unique_for')); 0 or
     * less falls back to an hour, because a 0-second lock never expires on Redis.
     */
    public function uniqueFor(): int
    {
        $seconds = (int) config('image-tools.unique_for', 3600);

        return $seconds > 0 ? $seconds : 3600;
    }
}
