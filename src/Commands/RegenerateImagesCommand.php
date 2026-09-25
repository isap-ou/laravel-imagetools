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
 * shape a killed optimizer leaves behind. An entry with a null path (a request
 * larger than the source) has no file by design and is left alone. Each entry is
 * rebuilt from the source recorded next to it, so the canonical seed, the key and
 * the filename stay the ones the manifest already holds.
 *
 * A rebuild can find the request larger than the source. The entry then gets a
 * null path, and its old file is deleted. The command reports such an entry on
 * its own line and in its own count. The manifest is rewritten entry by entry and
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
        $larger = 0;
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

            // The rebuild found the request larger than the source. The entry now
            // has no file, and generate() deletes its old file. The report shows
            // these entries apart from the entries that got a file again.
            if ($result['path'] === null) {
                $this->warn("No file [{$key}]: the request is larger than the source.");
                $larger++;

                continue;
            }

            $regenerated++;
        }

        $this->info(\sprintf(
            'Checked %d, healthy %d, %s, failed %d, skipped %d.',
            \count($entries),
            $healthy,
            // A dry run rebuilds nothing, so it cannot tell which entries are larger than the source.
            $dryRun
                ? "to regenerate {$regenerated}"
                : "regenerated {$regenerated}, larger than the source {$larger}",
            $failed,
            $skipped
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A stored file counts as broken when it is gone, or when it is on the disk
     * with no bytes in it — what an optimizer killed mid-write leaves behind.
     * An entry with a null path is a request larger than the source: it has no
     * file by design, so it is not broken.
     *
     * @param  array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}  $entry
     */
    protected function isBroken(array $entry): bool
    {
        if ($entry['path'] === null) {
            return false;
        }

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
     * @param  array{path: string|null, disk: string|null, source?: string, source_disk?: string|null}  $entry
     * @return array{path: string|null, disk: string|null}|null
     */
    protected function regenerate(array $entry): ?array
    {
        // A fresh instance per entry, so its Manifest reads the file as it stands
        // now. Writes already start from the file on disk; the fresh read matters
        // for what generate() reads before it writes, such as the entry whose old
        // file an oversize request deletes.
        $tool = app(ImageTools::class);

        if (($entry['source_disk'] ?? '') !== '') {
            $tool = $tool->disk($entry['source_disk']);
        }

        return $tool->generate($entry['source']);
    }
}
