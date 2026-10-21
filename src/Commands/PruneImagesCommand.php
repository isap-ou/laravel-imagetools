<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\Support\Manifest;

use function base_path;
use function config;
use function now;
use function preg_match;
use function realpath;
use function rtrim;
use function str_starts_with;

/**
 * Artisan command that deletes orphans: generated files on the output disk that
 * no entry of the default manifest references.
 *
 * Only names that ImageTools makes (image-tools/<slug>--<10 hex>.<ext>) count.
 * A file modified in the last hour is kept, because generate() uploads a file
 * before it writes the entry. Each delete runs under the manifest lock, after
 * the command reads the manifest again, so no writer can add an entry for the
 * file in between. The command refuses to run when its view of the manifest
 * looks wrong: an empty manifest, a per-release manifest, or more than half of
 * the files without an entry.
 */
class PruneImagesCommand extends Command
{
    /** Seconds a file must be old before it can count as an orphan. */
    protected const MIN_AGE = 3600;

    /** The names that PathResolver::storedFile() makes. */
    protected const NAME = '#^image-tools/[^/]+--[0-9a-f]{10}\.[A-Za-z0-9]+$#';

    protected $signature = 'imagetools:prune
        {--dry-run : List the orphans and delete nothing}
        {--force : Run also with a manifest under bootstrap/cache, or when more than half of the files are orphans}';

    protected $description = 'Delete generated ImageTools files that no manifest entry references.';

    public function handle(Manifest $manifest): int
    {
        $manifestPath = base_path(config('image-tools.manifest_path'));
        $disk = config('image-tools.disk');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $entries = $manifest->all();

        $this->line(\sprintf('Manifest [%s]: %d entries. Disk [%s].', $manifestPath, \count($entries), $disk));

        // Every file would count as an orphan. A lost manifest is rebuilt from the
        // files that are still there (see generate()), so they must stay.
        if (empty($entries)) {
            $this->error('The manifest is empty; nothing is pruned. Use imagetools:clear to delete every generated file.');

            return self::FAILURE;
        }

        // A per-release manifest knows only the entries of this release. The files
        // of the release before it (still serving, or brought back by a rollback)
        // would count as orphans.
        if (! $force && $this->isPerRelease($manifestPath)) {
            $this->error('The manifest is under bootstrap/cache, so each release has its own. Move it to shared storage (IMAGE_TOOLS_MANIFEST_PATH), or run again with --force.');

            return self::FAILURE;
        }

        try {
            $files = $this->listFiles($disk);
        } catch (\Throwable $e) {
            $this->logFailure('The files on the disk could not be listed.', ['disk' => $disk, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $referenced = $this->referenced($entries, $disk);
        $orphans = [];
        $recent = 0;
        $unknownAge = 0;
        $oldest = now()->getTimestamp() - self::MIN_AGE;

        foreach ($files as $path => $lastModified) {
            if (isset($referenced[$path])) {
                continue;
            }

            if ($lastModified === null) {
                $unknownAge++;
            } elseif ($lastModified > $oldest) {
                $recent++;
            } else {
                $orphans[$path] = $lastModified;
            }
        }

        if ($unknownAge > 0) {
            $this->warn("The disk gives no modification time for {$unknownAge} files without an entry; they are kept.");
        }

        // More than half of the files without an entry points at the wrong
        // manifest (another host's, or one that the web and the workers do not share).
        // A dry run deletes nothing, so it lists them anyway.
        if (! $force && \count($orphans) * 2 > \count($files)) {
            $message = \sprintf(
                '%d of %d files are orphans, more than half. Check that this is the manifest the web and the workers use, then run again with --force.',
                \count($orphans),
                \count($files)
            );

            if (! $dryRun) {
                $this->error($message . ' Nothing is pruned.');

                return self::FAILURE;
            }

            $this->warn($message);
        }

        $deleted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orphans as $path => $listedAt) {
            if ($dryRun) {
                $this->line("Would delete [{$path}].");

                continue;
            }

            try {
                $result = $manifest->whileLocked(fn (array $entries): string => $this->pruneLocked($entries, $disk, $path, $listedAt));
            } catch (\Throwable $e) {
                $this->logFailure("Failed [{$path}]: the manifest lock could not be taken.", ['path' => $path, 'error' => $e->getMessage()]);
                $failed++;

                continue;
            }

            match ($result) {
                'deleted' => $deleted++,
                'failed' => $failed++,
                default => $skipped++,
            };
        }

        $this->info(\sprintf(
            'Listed %d, orphans %d, %s, skipped %d, kept as too new %d, kept with no time %d, failed %d.',
            \count($files),
            \count($orphans),
            $dryRun ? 'to delete ' . \count($orphans) : "deleted {$deleted}",
            $skipped,
            $recent,
            $unknownAge,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check one orphan again and delete it, while the command holds the manifest
     * lock. $entries is the manifest as it is on disk now. Returns 'deleted',
     * 'failed', or 'skipped' when the file is no longer an orphan.
     *
     * @param  array<string, array{path: string|null, disk: string|null}>  $entries
     */
    protected function pruneLocked(array $entries, ?string $disk, string $path, int $listedAt): string
    {
        // A request may have adopted the file since the listing.
        if (isset($this->referenced($entries, $disk)[$path])) {
            $this->line("Skipped [{$path}]: an entry references it now.");

            return 'skipped';
        }

        // A worker may have encoded the file again since the listing; its entry
        // comes after the upload. The time of a missing file cannot be read.
        try {
            if (Storage::disk($disk)->lastModified($path) !== $listedAt) {
                $this->line("Skipped [{$path}]: the file changed since the listing.");

                return 'skipped';
            }
        } catch (\Throwable $e) {
            if (! $this->exists($disk, $path)) {
                $this->line("Skipped [{$path}]: the file is gone.");

                return 'skipped';
            }

            $this->logFailure("Failed [{$path}]: the file could not be checked again.", ['path' => $path, 'error' => $e->getMessage()]);

            return 'failed';
        }

        if (! $this->delete($disk, $path)) {
            return 'failed';
        }

        $this->line("Deleted [{$path}].");

        return 'deleted';
    }

    protected function exists(?string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->fileExists($path);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Print an error and log it, so a scheduled run with no captured output
     * still leaves a trace.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logFailure(string $message, array $context): void
    {
        $this->error($message . (isset($context['error']) ? ' ' . $context['error'] : ''));

        Log::warning('ImageTools: imagetools:prune: ' . $message, $context);
    }

    /**
     * The generated files directly in image-tools/ on the disk, with the time each
     * one was last modified (null when the disk does not tell). The time comes
     * from the listing, so S3 needs no extra request per file.
     *
     * @return array<string, int|null>
     */
    protected function listFiles(?string $disk): array
    {
        $files = [];

        foreach (Storage::disk($disk)->getDriver()->listContents('image-tools', false) as $item) {
            if ($item->isFile() && preg_match(self::NAME, $item->path()) === 1) {
                $files[$item->path()] = $item->lastModified();
            }
        }

        return $files;
    }

    /**
     * The paths on the disk that the entries reference. An entry with a null path
     * (a request larger than the source) references no file.
     *
     * @param  array<string, array{path: string|null, disk: string|null}>  $entries
     * @return array<string, true>
     */
    protected function referenced(array $entries, ?string $disk): array
    {
        $paths = [];

        foreach ($entries as $entry) {
            if ($entry['path'] !== null && $entry['disk'] === $disk) {
                $paths[$entry['path']] = true;
            }
        }

        return $paths;
    }

    /**
     * Whether the manifest file is inside the app's bootstrap/cache directory,
     * which a zero-downtime deploy makes new for each release.
     */
    protected function isPerRelease(string $manifestPath): bool
    {
        $cache = rtrim(realpath(base_path('bootstrap/cache')) ?: base_path('bootstrap/cache'), '/\\');
        $manifest = realpath($manifestPath) ?: $manifestPath;

        return str_starts_with($manifest, $cache . DIRECTORY_SEPARATOR);
    }

    /**
     * Delete one orphan. A disk can refuse the delete: with its 'throw' option on
     * it throws, with the option off delete() returns false. Both are logged and
     * reported, and the run goes on with the next file.
     */
    protected function delete(?string $disk, string $path): bool
    {
        try {
            $deleted = Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            Log::warning('ImageTools: an orphan could not be deleted.', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            $this->error("Failed [{$path}]: " . $e->getMessage());

            return false;
        }

        if ($deleted === false) {
            Log::warning('ImageTools: an orphan could not be deleted.', [
                'disk' => $disk,
                'path' => $path,
            ]);
            $this->error("Failed [{$path}]: the disk refused the delete.");

            return false;
        }

        return true;
    }
}
