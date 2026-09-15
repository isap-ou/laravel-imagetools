<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Support\Manifest;

use function app;

/**
 * Artisan command that rebuilds generated files the manifest already knows about.
 *
 * By default it only touches entries whose stored file is missing or empty — the
 * shape a killed optimizer leaves behind. Each entry is rebuilt from the source
 * recorded next to it, so the canonical seed, the key and the filename stay the
 * ones the manifest already holds. The manifest is rewritten entry by entry and
 * is never deleted: an entry that cannot be rebuilt keeps its current value.
 */
class RegenerateImagesCommand extends Command
{
    protected $signature = 'imagetools:regenerate
        {--all : Regenerate every entry, not only the broken ones}
        {--dry-run : Report what would be regenerated and write nothing}';

    protected $description = 'Regenerate ImageTools files that are missing or empty on the disk.';

    public function handle(Manifest $manifest): int
    {
        $entries = $manifest->all();

        if (empty($entries)) {
            $this->warn('The manifest is empty; there is nothing to regenerate.');

            return self::SUCCESS;
        }

        $regenerateAll = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');

        $healthy = 0;
        $skipped = 0;
        $regenerated = 0;
        $failed = 0;

        foreach ($entries as $key => $entry) {
            // Entries written before the source was recorded cannot be rebuilt:
            // nothing tells us which image and which query produced them.
            if (empty($entry['source'])) {
                $this->warn("Skipped [{$key}]: the entry has no recorded source.");
                $skipped++;

                continue;
            }

            try {
                $broken = $this->isBroken($entry);
            } catch (\Throwable $e) {
                $this->error("Failed [{$key}]: " . $e->getMessage());
                $failed++;

                continue;
            }

            if (! $broken && ! $regenerateAll) {
                $healthy++;

                continue;
            }

            if ($dryRun) {
                $this->line("Would regenerate [{$key}].");
                $regenerated++;

                continue;
            }

            try {
                $result = $this->regenerate($entry);
            } catch (\Throwable $e) {
                $this->error("Failed [{$key}]: " . $e->getMessage());
                $failed++;

                continue;
            }

            if ($result === null) {
                $this->error("Failed [{$key}]: the source could not be generated.");
                $failed++;

                continue;
            }

            $regenerated++;
        }

        $this->info(\sprintf(
            'Checked %d, healthy %d, %s %d, failed %d, skipped %d.',
            \count($entries),
            $healthy,
            $dryRun ? 'to regenerate' : 'regenerated',
            $regenerated,
            $failed,
            $skipped
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A stored file counts as broken when it is gone, or when it is on the disk
     * with no bytes in it — what an optimizer killed mid-write leaves behind.
     *
     * @param  array{path: string, disk: string, source?: string, source_disk?: string|null}  $entry
     */
    protected function isBroken(array $entry): bool
    {
        $disk = Storage::disk($entry['disk']);

        if (! $disk->fileExists($entry['path'])) {
            return true;
        }

        return $disk->size($entry['path']) === 0;
    }

    /**
     * Rebuild one entry from its recorded source. The source disk is carried
     * along so the derivative lands on the key the manifest already records.
     *
     * @param  array{path: string, disk: string, source?: string, source_disk?: string|null}  $entry
     * @return array{path: string, disk: string}|null
     */
    protected function regenerate(array $entry): ?array
    {
        // A fresh instance per entry, so its Manifest reads the file as it stands
        // now. The singleton would hold the snapshot it loaded when the run began,
        // and every write would replay that snapshot over later entries.
        $tool = app(ImageTools::class);

        if (($entry['source_disk'] ?? '') !== '') {
            $tool = $tool->disk($entry['source_disk']);
        }

        return $tool->generate($entry['source']);
    }
}
