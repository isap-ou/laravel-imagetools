<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;

use function base64_decode;
use function base_path;
use function var_export;

class RegenerateImagesCommandTest extends TestCase
{
    private string $png;

    private string $manifestFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

        File::ensureDirectoryExists(base_path('public/images'));
        File::put(base_path('public/images/regen.png'), $this->png);

        $this->manifestFile = base_path('bootstrap/cache/image-tools.php');
        if (File::exists($this->manifestFile)) {
            File::delete($this->manifestFile);
        }
    }

    public function test_an_empty_stored_file_is_regenerated(): void
    {
        $info = app(ImageTools::class)->generate('public/images/regen.png?w=8');
        Storage::disk('public')->put($info['path'], '');

        $this->artisan('imagetools:regenerate')->assertSuccessful();

        $this->assertGreaterThan(0, Storage::disk('public')->size($info['path']));
    }

    public function test_a_missing_stored_file_is_regenerated(): void
    {
        $info = app(ImageTools::class)->generate('public/images/regen.png?w=8');
        Storage::disk('public')->delete($info['path']);

        $this->artisan('imagetools:regenerate')->assertSuccessful();

        Storage::disk('public')->assertExists($info['path']);
        $this->assertGreaterThan(0, Storage::disk('public')->size($info['path']));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $info = app(ImageTools::class)->generate('public/images/regen.png?w=8');
        Storage::disk('public')->put($info['path'], '');

        $this->artisan('imagetools:regenerate', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Storage::disk('public')->size($info['path']));
    }

    public function test_a_healthy_entry_is_left_alone_unless_all_is_given(): void
    {
        $info = app(ImageTools::class)->generate('public/images/regen.png?w=8');
        Storage::disk('public')->put($info['path'], 'sentinel');

        $this->artisan('imagetools:regenerate')->assertSuccessful();
        $this->assertSame('sentinel', Storage::disk('public')->get($info['path']));

        $this->artisan('imagetools:regenerate', ['--all' => true])->assertSuccessful();
        $this->assertNotSame('sentinel', Storage::disk('public')->get($info['path']));
    }

    public function test_an_entry_without_a_recorded_source_is_skipped(): void
    {
        // The source of this legacy entry exists, so an implementation that
        // guessed it from the key would succeed — and write a file.
        File::put(base_path('public/images/legacy.png'), $this->png);

        $entries = [
            'public/images/legacy.png?w=8' => [
                'path' => 'image-tools/legacy--0123456789.png',
                'disk' => 'public',
            ],
        ];
        File::put($this->manifestFile, "<?php\n\nreturn " . var_export($entries, true) . ";\n");

        $this->artisan('imagetools:regenerate')
            ->expectsOutputToContain('skipped 1')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('public')->files('image-tools'));
        $this->assertSame($entries, require $this->manifestFile);
    }

    public function test_a_failed_entry_keeps_its_manifest_record_and_the_others_are_rewritten(): void
    {
        File::put(base_path('public/images/gone.png'), $this->png);

        $first = app(ImageTools::class)->generate('public/images/regen.png?w=8');
        $broken = app(ImageTools::class)->generate('public/images/gone.png?w=8');
        $last = app(ImageTools::class)->generate('public/images/regen.png?w=16');

        // The middle entry can no longer be rebuilt: its source is gone.
        File::delete(base_path('public/images/gone.png'));

        foreach ([$first, $broken, $last] as $info) {
            Storage::disk('public')->put($info['path'], 'sentinel');
        }

        $this->artisan('imagetools:regenerate', ['--all' => true])->assertFailed();

        $entries = require $this->manifestFile;
        $this->assertCount(3, $entries);

        $this->assertNotSame('sentinel', Storage::disk('public')->get($first['path']));
        $this->assertNotSame('sentinel', Storage::disk('public')->get($last['path']));
        $this->assertSame('sentinel', Storage::disk('public')->get($broken['path']));
    }
}
