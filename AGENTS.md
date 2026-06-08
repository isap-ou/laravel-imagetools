# AGENTS.md

Guidance for AI coding agents working on **`isapp/laravel-imagetools`**.

## What this is

A Laravel package for **deterministic, query‑driven image generation** (inspired by
vite‑imagetools). `ImageTools::asset('path/to/img.jpg?w=1200&format=webp')` produces a
hash‑named derivative on a configured filesystem disk and records a `path?query → file`
mapping in a PHP manifest, so later calls are cheap. Generation can happen on demand,
ahead of time (scanner command), or on a queue.

## Setup

- PHP **^8.2**. Install: `composer install`.
- Dependencies live in `vendor/` — never assume an API; read the installed source.

## Commands

- **Tests:** `composer test` (PHPUnit via Orchestra Testbench).
  - Single group: `vendor/bin/phpunit --group s3`.
  - The `s3` group exercises a real S3 endpoint and **skips** unless `AWS_ENDPOINT` +
    `AWS_BUCKET` are set — see the README "Testing" section for the local MinIO recipe.
- **Lint:** `composer lint` (Laravel Pint). Check‑only: `vendor/bin/pint --test`.

## Layout

- `src/ImageTools.php` — core: `asset()`, `generate()`, the canonical seed, the manifest,
  and queued dispatch.
- `src/Jobs/GenerateImageJob.php` — queued generation (`ShouldQueue` + `ShouldBeUnique`).
- `src/Commands/GenerateImagesCommand.php` — scans Blade/PHP (nikic/php-parser) and
  pre‑generates; `src/Commands/ClearGeneratedImagesCommand.php` — removes generated files
  and the manifest.
- `src/Facades/ImageTools.php`, `src/ServiceProvider.php`, `config/image-tools.php`.
- `tests/` — Feature + Unit (Testbench `TestCase`).

## Conventions

- PSR‑4 `Isapp\ImageTools\` → `src/`. `declare(strict_types=1)` in every PHP file.
- Code style is **Laravel Pint** (`pint.json`); run `composer lint` before pushing.
- **Conventional Commits.** Branch off `main` — never commit to `main` directly. PRs are
  **squash‑merged**.
- CI must stay green: matrix (PHP 8.2–8.4 × Laravel 12/13), Pint, and the MinIO S3 job.

## Invariants — do not break

- The manifest key **and** the generated filename both derive from one canonical "seed":
  the query reduced to the supported keys (`w, h, q, fit, format`), sorted. `asset()`
  (read path) and `generate()` (write path) MUST use the same derivation — see
  `getPathSeed()` and `storedFileInfo()`. Divergence causes cache misses and broken URLs.
- `queue` is a **control flag**, deliberately excluded from the seed, so it never affects
  the filename or manifest key.
- Default (non‑queued) behaviour must remain fully **synchronous** and unchanged.

## Where to read more

- [README.md](README.md) — usage, query options, queued generation, config, troubleshooting.
- [CHANGELOG.md](CHANGELOG.md) — notable changes.
