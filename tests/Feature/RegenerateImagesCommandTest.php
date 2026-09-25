<?php

declare(strict_types=1);

namespace Isapp\ImageTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Isapp\ImageTools\ImageTools;
use Isapp\ImageTools\Tests\TestCase;

use function base_path;
use function getimagesizefromstring;
use function var_export;

class RegenerateImagesCommandTest extends TestCase
{
    private string $png;

    private string $manifestFile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->png = $this->pngBytes();

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

    public function test_an_entry_without_a_file_is_healthy_and_all_rebuilds_it_by_the_current_rules(): void
    {
        File::put(base_path('public/images/regen-small.png'), $this->pngBytes(40, 20));

        $oversize = app(ImageTools::class)->generate('public/images/regen-small.png?w=80');
        $this->assertNull($oversize['path']);

        $this->artisan('imagetools:regenerate')
            ->expectsOutputToContain('healthy 1')
            ->assertSuccessful();
        $this->assertSame([], Storage::disk('public')->files('image-tools'));

        // A config change applies to the existing entry only through --all.
        config()->set('image-tools.allow_upscale', true);

        $this->artisan('imagetools:regenerate', ['--all' => true])->assertSuccessful();

        // assertExists() checks nothing for a null path, so the path is checked first.
        $entry = (require $this->manifestFile)['public/images/regen-small.png?w=80'];
        $this->assertNotNull($entry['path']);

        [$width] = getimagesizefromstring(Storage::disk('public')->get($entry['path']));
        $this->assertSame(80, $width);
    }

    public function test_all_turns_an_enlarged_entry_into_one_without_a_file(): void
    {
        File::put(base_path('public/images/regen-small.png'), $this->pngBytes(40, 20));

        // The entry and the file an earlier, enlarging version left behind.
        config()->set('image-tools.allow_upscale', true);
        $old = app(ImageTools::class)->generate('public/images/regen-small.png?w=80');
        config()->set('image-tools.allow_upscale', false);

        // The run deletes the old file, so the report names the entry and does not count it as regenerated.
        $this->artisan('imagetools:regenerate', ['--all' => true])
            ->expectsOutputToContain('No file [public/images/regen-small.png?w=80]')
            ->expectsOutputToContain('regenerated 0, larger than the source 1')
            ->assertSuccessful();

        $this->assertNull((require $this->manifestFile)['public/images/regen-small.png?w=80']['path']);
        Storage::disk('public')->assertMissing($old['path']);
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
