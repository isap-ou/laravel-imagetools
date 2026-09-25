# AGENTS.md

Guidance for AI coding agents working on **`isapp/laravel-imagetools`**.

## What this is

A Laravel package for **deterministic, query‑driven image generation** (inspired by
vite‑imagetools). `ImageTools::asset('path/to/img.jpg?w=1200&format=webp')` produces a
hash‑named derivative on a configured filesystem disk and records a `path?query → file`
mapping in a PHP manifest, so later calls are cheap. Generation can happen on demand,
ahead of time (scanner command), or on a queue.

## Setup

- PHP **^8.2** with `ext-gd` (the tests draw their fixtures with GD). Install: `composer install`.
- Dependencies live in `vendor/` — never assume an API; read the installed source.

## Commands

- **Tests:** `composer test` (PHPUnit via Orchestra Testbench).
  - Single group: `vendor/bin/phpunit --group s3`.
  - The `s3` group exercises a real S3 endpoint and **skips** unless `AWS_ENDPOINT` +
    `AWS_BUCKET` are set — see the README "Testing" section for the local MinIO recipe.
- **Lint:** `composer lint` (Laravel Pint). Check‑only: `vendor/bin/pint --test`.

## Layout

- `src/ImageTools.php` — the orchestrator (`asset()`, `generate()`, `disk()`, queued
  dispatch). Depends on three injected collaborators in `src/Support/`:
  - `Support/Manifest.php` — the PHP manifest state + persistence (load/has/get/put/clear;
    writes take the `<manifest>.lock` lock).
  - `Support/PathResolver.php` — canonical `seed()` and the deterministic `storedFile()`.
  - `Support/SourceReader.php` — resolves a source to a local path (local or disk→temp).
  Wiring lives in `src/ServiceProvider.php` (`Manifest` is bound with its config path; the
  rest auto‑resolve).
- `src/Jobs/GenerateImageJob.php` — queued generation (`ShouldQueue` + `ShouldBeUnique`).
- `src/Commands/GenerateImagesCommand.php` — scans Blade/PHP (nikic/php-parser) and
  pre‑generates; `src/Commands/ClearGeneratedImagesCommand.php` — removes generated files
  and the manifest; `src/Commands/RegenerateImagesCommand.php` — rebuilds entries whose
  stored file is missing or empty, from the source each entry records.
- `src/Facades/ImageTools.php`, `src/ServiceProvider.php`, `config/image-tools.php`.
- `tests/` — Feature + Unit (Testbench `TestCase`).

## Conventions

- PSR‑4 `Isapp\ImageTools\` → `src/`. `declare(strict_types=1)` in every PHP file.
- Code style is **Laravel Pint** (`pint.json`); run `composer lint` before pushing.
- **Conventional Commits.** Branch off `main` — never commit to `main` directly. PRs are
  **squash‑merged**.
- CI must stay green: matrix (PHP 8.2–8.4 × Laravel 12/13), Pint, the Imagick-driver job, and
  the MinIO S3 job.

## Invariants — do not break

- The manifest key **and** the generated filename both derive from one canonical "seed":
  the query reduced to the supported keys (`w, h, q, fit, format, lossless`), sorted. `asset()`
  (read path) and `generate()` (write path) MUST use the same derivation — both go through
  `PathResolver::seed()` / `storedFile()`. Divergence causes cache misses and broken URLs.
- `queue` is a **control flag**, deliberately excluded from the seed, so it never affects
  the filename or manifest key.
- The **source disk** (set via `disk()`) is folded into the seed so the same path read from
  different disks never collides; it is used only as a key/hash input, never parsed as a path.
- Default (non‑queued, local‑source) behaviour stays fully **synchronous**; only the `queue` flag
  defers generation.
- A manifest entry records the `source` and `source_disk` it was built from. `imagetools:regenerate`
  rebuilds from those recorded values — the seed in the key is never parsed back into a path and a
  disk. The command also never deletes the manifest or an entry.
- A resize by `w` or `h` never enlarges the source unless `allow_upscale` is on. Such a request
  gets an entry with `path => null` and no file, and `asset()` returns `null` for it. `fit` is
  never affected. `allow_upscale` is not part of the seed. Every reader of an entry (`asset()`,
  `imagetools:clear`, `imagetools:regenerate`) must handle a null `path`.
- `Manifest::put()` writes under a lock (`<manifest>.lock`) and starts from the file on disk, then
  changes one key; `Manifest::clear()` reads and deletes under the same lock. Never write an
  in‑memory copy of a whole manifest back: a queue worker holds the `image-tools` singleton between
  jobs, and its copy can be old. The lock must never make a write fail — it falls back to a
  read‑only handle, then to no lock with a warning.
- A clean-up delete of an old file (`recordOversize()`) comes after the manifest write and never
  fails the request: a refused delete is logged as a warning.

## Where to read more

- [README.md](README.md) — usage, query options, queued generation, config, troubleshooting.
- [CHANGELOG.md](CHANGELOG.md) — notable changes.
