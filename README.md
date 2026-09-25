# Laravel Image Tools

Deterministic, query‑driven image generation for Laravel — inspired by **vite-imagetools**.

<p><a href="https://isapp.be/laravel-imagetools" target="_blank"><img src="https://static.isap.me/laravel-imagetools.png" alt="Laravel Image Tools by ISAPP" /></a></p>

[![Packagist](https://img.shields.io/packagist/v/isapp/laravel-imagetools.svg)](https://packagist.org/packages/isapp/laravel-imagetools)
[![Tests](https://github.com/isap-ou/laravel-imagetools/actions/workflows/run-tests.yml/badge.svg)](https://github.com/isap-ou/laravel-imagetools/actions/workflows/run-tests.yml)
[![PHP](https://img.shields.io/packagist/php-v/isapp/laravel-imagetools.svg)](https://packagist.org/packages/isapp/laravel-imagetools)
[![License](https://img.shields.io/github/license/isap-ou/laravel-imagetools.svg)](#license)

- Call it once in Blade/PHP and get a public URL:
  ```blade
  {{ ImageTools::asset('public/images/hero.jpg?w=1200&h=630&fit=contain&format=webp&q=82') }}
  ```
- Files are written to your configured filesystem disk with **stable names**
  (e.g. `hero--a1b2c3d4e5.webp`), perfect for long‑lived CDN caching.
- A tiny **PHP manifest** maps your canonical request to the stored file, so subsequent calls are instant.

---

## Features

- 🔁 **Deterministic filenames** based on the source + sorted query options
- 🧩 **Simple query API**: `w`, `h`, `fit`, `q`, `format`
- 📦 **One disk to rule them all** — works with `public`, S3/R2 or any Laravel disk
- 🔎 **Scanner command** to pre‑generate all images referenced in your code
- 🧹 **Clear command** to remove generated files & the manifest
- ⏳ **Deferred generation** via a `queue` flag — defer heavy/responsive work to the queue
- 🚫 **No upscaling** by default — a `w`/`h` request without `fit` larger than the source returns `null` instead of an enlarged file

## Requirements

- PHP **8.2+**
- Laravel **10+** (works with 10/11/12)
- Image driver: **Imagick** (recommended) or **GD** for [`spatie/image`](https://github.com/spatie/image)

## Installation

```bash
composer require isapp/laravel-imagetools
```


Auto‑discovery will register the service provider and the `ImageTools` facade.

### Publish config (optional)
If you want to customize defaults, publish the config file:

```bash
php artisan vendor:publish --provider="Isapp\\ImageTools\\ServiceProvider"
```

## Quick start

```blade
<img
  src="{{ ImageTools::asset('public/images/placeholder.jpg?w=640&q=75&format=webp') }}"
  width="640"
  height="360"
  alt="Placeholder"
/>
```

> When the source is narrower than 640px, `asset()` returns `null` and `src` is empty.
> See [Larger than the source](#larger-than-the-source).

**Pure PHP**

```php
use Isapp\\ImageTools\\Facades\\ImageTools;

// A string URL, or null when the source is narrower than 640px (see "Larger than the source").
$url = ImageTools::asset('resource/images/placeholder.jpg?w=640&q=75&format=webp');
```

**Source on another disk** — read the original from any Laravel disk (e.g. S3),
mirroring `Storage::disk()` (see [Reading the source from a disk](#reading-the-source-from-a-disk-eg-s3)):

```php
ImageTools::disk('s3')->asset('assets/hero.jpg?w=1200&format=webp');
```

**Also supported** (and detected by the scanner):

```blade
{{ app(Isapp\ImageTools\ImageTools::class)->asset('resource/images/pic.jpg?w=800') }}
{{ app('image-tools')->asset('resource/images/pic.jpg?w=800') }}
{{ \Illuminate\Support\Facades\App::make('image-tools')->asset('resource/images/pic.jpg?w=800') }}
{{ App::make(Isapp\ImageTools\ImageTools::class)->asset('resource/images/pic.jpg?w=800') }}
```

## Configuration

All options live in `config/image-tools.php` (with inline comments). You can also control them via ENV:

```dotenv
IMAGE_TOOLS_DISK=public
IMAGE_TOOLS_ALLOW_UPSCALE=false
IMAGE_TOOLS_MANIFEST_PATH=bootstrap/cache/image-tools.php
IMAGE_TOOLS_BLADE_PATHS=resources/views,modules/*/resources/views
IMAGE_TOOLS_PHP_PATHS=app,modules

# Deferred generation (optional)
IMAGE_TOOLS_QUEUE_CONNECTION=redis   # defaults to QUEUE_CONNECTION
IMAGE_TOOLS_QUEUE_NAME=images        # defaults to "default"
IMAGE_TOOLS_QUEUE_UNIQUE_FOR=3600
```

Key options:

- **`disk`** — Laravel filesystem disk where processed files are written and served from (`public`, `s3`, `r2`, …).
- **`allow_upscale`** — `false` by default: a resize by `w` or `h` larger than the source stores no file, and `asset()` returns `null`. See [Larger than the source](#larger-than-the-source).
- **`manifest_path`** — Path to the PHP manifest file that stores the mapping (relative paths resolve from the project base path).
- **`blade_paths`** — Directories with Blade templates to scan for usages.
- **`php_paths`** — Additional PHP directories to scan (controllers, services, etc.).

> The request is **canonicalized**: query keys are sorted before hashing, so `?h=630&w=1200` equals `?w=1200&h=630`.

> **Zero‑downtime deploys (Forge, Envoyer, Deployer, Vapor):** the manifest is written at runtime when `asset()` generates an image on demand. `bootstrap/cache` is **per‑release**, so those entries are lost on the next deploy (the images are simply regenerated). If you rely on on‑demand generation, point the manifest at the **shared** `storage` directory, e.g. `IMAGE_TOOLS_MANIFEST_PATH=storage/app/image-tools.php`. If you only pre‑generate at build time with `imagetools:generate`, the default is fine.

### Query options

| Key      | Type       | Description                                                                                           |
|:---------|:-----------|:------------------------------------------------------------------------------------------------------|
| `w`      | `int`      | Target width (px). Without `fit` and greater than the source width, no file is stored — see [Larger than the source](#larger-than-the-source). |
| `h`      | `int`      | Target height (px). Without `w` or `fit` and greater than the source height, no file is stored.       |
| `fit`    | `enum`     | Geometry mode from `Spatie\Image\Enums\Fit` (e.g. `Contain`, `Fill`, `Max`, …). Requires `w` and `h`. |
| `q`      | `int`      | Output quality (`1..100`).                                                                            |
| `format` | `enum`     | Output format: `jpeg`, `png`, `gif`, `webp`, `avif`.                                                  |
| `lossless` | `bool`   | Lossless WebP: `1`, `true`, `on` or `yes` switch it on. Takes effect when the output is a WebP **and** the driver is Imagick; otherwise the ordinary encode runs. A switch that is on is part of the canonical name, so the lossless variant is its own file; a switch that is off resolves to the same file as the plain call. |

> `queue` is a **control flag**, not a transform — see below. It is excluded from the canonical name, so `?w=800` and `?w=800&queue=1` resolve to the **same** file.

### Larger than the source

A resize by `w` or `h` does not enlarge a source that is smaller than the request.
An enlarged file holds no more detail than the source, and it is much heavier. For
example, `?w=3840` on a 1280px source stores no file. The manifest records the
request with `'path' => null`, and `asset()` returns `null`. Later calls read that
entry and do not load the source again.

- `w` counts when it is greater than the source width. `h` counts only without `w`,
  when it is greater than the source height. The size is measured after EXIF rotation.
  GD applies that rotation only when `ext-exif` and `ext-fileinfo` are loaded.
- `w` equal to the source width is not larger: it stores a file at the source size.
- `fit` is not affected. For example, `fit=crop&w=1200&h=630` still produces 1200×630
  from a smaller source.
- Set `allow_upscale` to `true` (`IMAGE_TOOLS_ALLOW_UPSCALE=true`) to enlarge as
  version 1.3 and earlier did.

`{{ null }}` prints nothing, but the text around it still prints. A `srcset` needs a
check before each candidate:

```blade
@php
    $srcset = collect([
        768 => ImageTools::asset('public/images/hero.jpg?w=768&format=webp'),
        1536 => ImageTools::asset('public/images/hero.jpg?w=1536&format=webp'),
        3840 => ImageTools::asset('public/images/hero.jpg?w=3840&format=webp'),
    ])->filter()->map(fn ($url, $width) => "{$url} {$width}w")->implode(', ');
@endphp

<img
  src="{{ ImageTools::asset('public/images/hero.jpg?format=webp') }}"
  srcset="{{ $srcset }}"
  sizes="100vw"
  alt="Hero"
/>
```

The `src` request has no `w`, so it is never larger than the source, and `asset()`
never returns `null` for it. The package does not add a candidate at the source
width: for a 1280px source, the list above keeps only `768w`. Add widths that match
your sources if you need more.

**Upgrading from 1.3 or earlier:** existing entries keep their enlarged files,
because the key and the filename do not change. Two commands apply the rule:

- `php artisan imagetools:regenerate --all` rebuilds every entry with a recorded
  source. An entry larger than the source gets `'path' => null`, and its enlarged
  file is deleted. The command lists each such entry and counts it as
  `larger than the source`. Entries without a recorded source are skipped.
- `php artisan imagetools:generate` deletes the files that the manifest references
  and the manifest itself. Then it builds every request it finds in the code by the
  new rule. A build pipeline that runs it applies the rule on the next deploy.

The enlarged file is deleted even when the manifest no longer holds its entry, for
example a per‑release `bootstrap/cache`. Pages cached before the run (full‑page
cache, CDN HTML) still point to the deleted files, so clear those caches after it.
When opcache does not check timestamps (`opcache.validate_timestamps=0`), reload
PHP‑FPM too: the web processes keep their compiled copy of the manifest. Restart
long‑lived processes as well (`php artisan queue:restart`, `php artisan octane:reload`):
they keep the manifest in memory and would print URLs to the deleted files. When
several hosts keep their own manifest but share one output disk, run the command on
each host: the files it deletes are the ones the other manifests still point to. A
later change to `allow_upscale` needs the same steps.

A `null` entry stays `null` when you replace its source with a larger image: the
key does not change, so nothing reads the source again. Run
`imagetools:regenerate --all` after you replace a source.

## Deferred (queued) generation

On a page with many images — especially responsive `srcset` with several widths
— generating them all on the first request can be slow. Add a truthy **`queue`**
flag to defer generation to the queue:

```blade
<img src="{{ ImageTools::asset('public/images/hero.jpg?w=1200&format=webp&queue=1') }}">
```

When the image hasn't been generated yet:

- `asset()` returns the **final, deterministic URL immediately** (filenames are a
  hash of the source + options, so the URL is known before the file exists).
- A `GenerateImageJob` is dispatched to the queue; the file appears once a worker
  processes it. Until then the URL 404s — make sure a worker is running
  (`php artisan queue:work`).
- For a request [larger than the source](#larger-than-the-source), `asset()` cannot
  know this before the worker reads the source. The worker stores no file, so the
  URL from the first render stays a 404. Calls after the worker has run return `null`.

The job is **unique** per derivative (`ShouldBeUnique`), so many concurrent page
renders of the same not-yet-generated image collapse into a single job instead of
a storm of duplicates. This requires a cache store that supports atomic locks
(`file`, `redis`, `database`, `memcached`, …).

Configuration (all optional — see `config/image-tools.php`):

- **`queue_connection`** — falls back to `QUEUE_CONNECTION`. If that resolves to
  `sync`, the job runs inline (no real deferral) — expected Laravel behaviour.
- **`queue_name`** — queue to dispatch on (default `"default"`).
- **`unique_for`** — seconds the uniqueness lock is held (default `3600`).

> Pre-generating with `php artisan imagetools:generate` ignores the `queue` flag
> and produces the same files, so you can warm everything at build time instead.

## Reading the source from a disk (e.g. S3)

By default the **source** image is read locally, relative to `base_path()`. To
read the original from a configured Laravel filesystem disk instead — for example
an S3 bucket — scope the call with `disk()`, mirroring `Storage::disk()`:

```php
use Isapp\ImageTools\Facades\ImageTools;

// Reads s3://<bucket>/assets/hero.jpg, processes it, writes the result to the
// configured output disk (image-tools.disk).
ImageTools::disk('s3')->asset('assets/hero.jpg?w=1200&format=webp');
```

```blade
<img src="{{ ImageTools::disk('s3')->asset('assets/hero.jpg?w=800') }}">
```

- The original is streamed to a temporary local file (the image driver loads from
  a path), processed, and the temp copy is removed.
- The **source disk participates in the identity**: the same path read from
  different disks produces distinct files and manifest keys (no collisions).
- `disk()` returns a scoped copy — it does not mutate the shared instance.
- The **output** disk is still `config('image-tools.disk')`; `disk()` only changes
  where the *source* is read from.

> The scanner command detects plain `ImageTools::asset('…')` calls; the fluent
> `disk('…')->asset('…')` form is generated on demand (or via the queue), not
> pre-discovered at build time.

## Commands

### Pre‑generate from code (CI‑friendly)

Scans your codebase and generates images for discovered usages.

```bash
php artisan imagetools:generate
```

The scanner looks into `config('image-tools.blade_paths')` and `config('image-tools.php_paths')` and detects:

- `ImageTools::asset('…')`
- Container‑resolved calls (e.g. `app(ImageTools::class)->asset('…')`, `app('image-tools')->asset('…')`, `App::make(...)->asset('…')`)

### Clear generated files

Removes the manifest file, then deletes every file it referenced. The manifest is read
and removed under its write lock; the `.lock` file stays in place.

```bash
php artisan imagetools:clear
```

### Regenerate broken files

Walks the manifest and rebuilds every entry whose stored file is **missing or
empty** — the shape a killed optimizer leaves behind. Each entry is rebuilt from
the source recorded next to it, so the key and the filename stay the same.

```bash
php artisan imagetools:regenerate
```

| Option      | Effect                                                        |
|:------------|:--------------------------------------------------------------|
| `--all`     | Regenerate every entry, not only the broken ones.             |
| `--dry-run` | Report what would be regenerated and write nothing.           |

The manifest is rewritten entry by entry and is never deleted, so the site keeps
serving while the command runs. The exception is an entry that becomes
[larger than the source](#larger-than-the-source): its enlarged file is deleted, and
pages cached before the run still point to it. An entry that cannot be rebuilt keeps its current
value and is reported: the command exits with a non‑zero status when anything
failed. Entries written before the source was recorded are skipped — run
`imagetools:generate` to rebuild those from the code.

An entry with `'path' => null` is a request [larger than the source](#larger-than-the-source).
It has no file by design, so it counts as healthy. `--all` rebuilds it by the current
`allow_upscale` value.

A rebuild that finds the request larger than the source prints
`No file [<key>]: the request is larger than the source.` The summary counts it as
`larger than the source`, not as `regenerated`. The exit status stays zero. A
`--dry-run` rebuilds nothing, so it cannot find these entries.

> Each write takes a lock on the manifest and starts from the file as it is on
> disk, so an entry that a web request or a queue worker adds while the command
> runs is kept. A long‑lived process no longer writes its old copy of the manifest
> back over the command's changes. When the lock cannot be taken, the write goes
> ahead without it and a warning is logged.

## What gets written

- A processed file on the configured **disk**, under `image-tools/<name>--<hash>.<ext>`.
- A lock file next to the manifest (`image-tools.php.lock`). Writers take turns on it;
  it stays in place and holds no data.
- A PHP **manifest** (by default `bootstrap/cache/image-tools.php`) with entries like:
  ```php
  return [
      'resource/images/hero.jpg?h=630&w=1200&fit=contain&format=webp&q=82' => [
          'path' => 'image-tools/hero--a1b2c3d4e5.webp',
          'disk' => 'public',
          // The source this file was built from, used by imagetools:regenerate.
          'source' => 'resource/images/hero.jpg?w=1200&h=630&fit=contain&format=webp&q=82',
          'source_disk' => null,
      ],
      // A request larger than the source: no file, and asset() returns null.
      'resource/images/icon.png?w=3840' => [
          'path' => null,
          'disk' => null,
          'source' => 'resource/images/icon.png?w=3840',
          'source_disk' => null,
      ],
  ];
  ```

## Tips

- **Deterministic names** → long CDN cache is safe; change options or the source to bust the cache.
- **Disks**: for S3/R2 configure a public bucket or use signed URLs as needed.
- **Quality/Formats**: `webp`/`avif` usually win; measure before/after.

## Troubleshooting

- **`Class ...\ImageTools not found`** — ensure the package is installed and auto‑discovered; run `composer dump-autoload`.
- **`ImagickException` / missing extension** — install and enable Imagick (preferred) or GD for your PHP runtime.
- **`width(): Argument #1 must be of type int`** — pass numeric values in the query (`w=640`, not `w=640px`).
- **`fit` requires `w` and `h`** — when using `fit`, provide both dimensions.
- **No URL / 404** — check the configured `disk` has a URL generator (`php artisan storage:link` for `public` disk).
- **Empty `src` / `asset()` returns `null`** — the request asks for a `w` or `h` larger than the source. See [Larger than the source](#larger-than-the-source): leave the candidate out, ask for a smaller size, or set `allow_upscale`. With `queue=1`, the first render gets a URL that stays a 404 for such a request.

## Testing

```bash
composer test
```

The tests draw their image fixtures with GD, so development needs `ext-gd`.

The suite includes an **S3 integration test** (PHPUnit group `s3`) that runs
against a real S3‑compatible endpoint to verify uploads, URL generation and the
clear command. It is **skipped** unless `AWS_ENDPOINT` + `AWS_BUCKET` are set, so
the default run needs no infrastructure. To run it locally against MinIO:

```bash
# MinIO's own images are no longer public; this is Chainguard's MinIO build.
docker run -d -p 9000:9000 -e MINIO_ROOT_USER=minio \
  -e MINIO_ROOT_PASSWORD=minio12345 cgr.dev/chainguard/minio:latest server /data
aws --endpoint-url http://127.0.0.1:9000 s3 mb s3://test   # create the bucket

AWS_ENDPOINT=http://127.0.0.1:9000 AWS_BUCKET=test \
  AWS_ACCESS_KEY_ID=minio AWS_SECRET_ACCESS_KEY=minio12345 \
  AWS_USE_PATH_STYLE_ENDPOINT=true vendor/bin/phpunit --group s3
```

CI runs this automatically in a dedicated MinIO job.

## Versioning

This package follows [Semantic Versioning](https://semver.org). See
[CHANGELOG.md](CHANGELOG.md) for release notes.

## Security

If you discover a security issue, please email **contact@isapp.be** instead of opening a public issue.

## Contributing

Contributions are welcome! If you have suggestions for improvements, new features, or find any issues, feel free to
submit a pull request or open an issue in this repository.

Thank you for helping make this package better for the community!

## License

This project is open-sourced software licensed under the [MIT License](https://opensource.org/licenses/MIT).

You are free to use, modify, and distribute it in your projects, as long as you comply with the terms of the license.

## Credits

Built by [ISAPP](https://isapp.be). Uses the excellent [`spatie/image`](https://github.com/spatie/image).

---

Check out our software development services at [isapp.be](https://isapp.be).

