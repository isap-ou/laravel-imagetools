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

**Pure PHP**

```php
use Isapp\\ImageTools\\Facades\\ImageTools;

$url = ImageTools::asset('resource/images/placeholder.jpg?w=640&q=75&format=webp');
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
- **`manifest_path`** — Path to the PHP manifest file that stores the mapping (relative paths resolve from the project base path).
- **`blade_paths`** — Directories with Blade templates to scan for usages.
- **`php_paths`** — Additional PHP directories to scan (controllers, services, etc.).

> The request is **canonicalized**: query keys are sorted before hashing, so `?h=630&w=1200` equals `?w=1200&h=630`.

> **Zero‑downtime deploys (Forge, Envoyer, Deployer, Vapor):** the manifest is written at runtime when `asset()` generates an image on demand. `bootstrap/cache` is **per‑release**, so those entries are lost on the next deploy (the images are simply regenerated). If you rely on on‑demand generation, point the manifest at the **shared** `storage` directory, e.g. `IMAGE_TOOLS_MANIFEST_PATH=storage/app/image-tools.php`. If you only pre‑generate at build time with `imagetools:generate`, the default is fine.

### Query options

| Key      | Type       | Description                                                                                           |
|:---------|:-----------|:------------------------------------------------------------------------------------------------------|
| `w`      | `int`      | Target width (px).                                                                                    |
| `h`      | `int`      | Target height (px).                                                                                   |
| `fit`    | `enum`     | Geometry mode from `Spatie\Image\Enums\Fit` (e.g. `Contain`, `Fill`, `Max`, …). Requires `w` and `h`. |
| `q`      | `int`      | Output quality (`1..100`).                                                                            |
| `format` | `enum`     | Output format: `jpeg`, `png`, `gif`, `webp`, `avif`.                                                  |

> `queue` is a **control flag**, not a transform — see below. It is excluded from the canonical name, so `?w=800` and `?w=800&queue=1` resolve to the **same** file.

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

Deletes all files referenced in the current manifest and then removes the manifest file.

```bash
php artisan imagetools:clear
```

## What gets written

- A processed file on the configured **disk**, under `image-tools/<name>--<hash>.<ext>`.
- A PHP **manifest** (by default `bootstrap/cache/image-tools.php`) with entries like:
  ```php
  return [
      'resource/images/hero.jpg?h=630&w=1200&fit=contain&format=webp&q=82' => [
          'path' => 'image-tools/hero--a1b2c3d4e5.webp',
          'disk' => 'public',
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

## Testing

```bash
composer test
```

The suite includes an **S3 integration test** (PHPUnit group `s3`) that runs
against a real S3‑compatible endpoint to verify uploads, URL generation and the
clear command. It is **skipped** unless `AWS_ENDPOINT` + `AWS_BUCKET` are set, so
the default run needs no infrastructure. To run it locally against MinIO:

```bash
docker run -d -p 9000:9000 -e MINIO_ROOT_USER=minio \
  -e MINIO_ROOT_PASSWORD=minio12345 minio/minio server /data
aws --endpoint-url http://127.0.0.1:9000 s3 mb s3://test   # create the bucket

AWS_ENDPOINT=http://127.0.0.1:9000 AWS_BUCKET=test \
  AWS_ACCESS_KEY_ID=minio AWS_SECRET_ACCESS_KEY=minio12345 \
  AWS_USE_PATH_STYLE_ENDPOINT=true vendor/bin/phpunit --group s3
```

CI runs this automatically in a dedicated MinIO job.

## Versioning

This package follows **SemVer**. Until `1.0.0`, minor versions (`0.x`) may include breaking changes.

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

