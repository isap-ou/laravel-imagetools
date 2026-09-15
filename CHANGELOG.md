# Changelog

All notable changes to `isapp/laravel-imagetools` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org).

## [Unreleased]

## [1.3.0] — 2026-09-15

### Added
- `imagetools:regenerate` rebuilds manifest entries whose stored file is missing
  or empty. Each entry is rebuilt from the source now recorded next to it, so the
  canonical key and the filename do not move. `--all` regenerates every entry and
  `--dry-run` only reports. The manifest is rewritten entry by entry and is never
  deleted, so an entry that cannot be rebuilt keeps its current value. ([#14])
- Manifest entries carry the `source` (`path?query`) and `source_disk` they were
  built from. `asset()` still reads `path` and `disk` only, so entries written by
  earlier versions stay readable. ([#14])
- A `lossless` query key for WebP output, e.g.
  `ImageTools::asset('hero.png?format=webp&lossless=1')` — `1`, `true`, `on` and
  `yes` all switch it on, the way the `queue` flag reads them. It takes effect when the
  output is a WebP and the driver is Imagick; the encode then runs lossless and the
  optimizer re-encodes with `cwebp -lossless` instead of the lossy default. Under
  GD, or for any other format, the ordinary encode runs. The key is part of the
  canonical seed, so the lossless variant is its own file and existing names are
  unaffected; a switch that is off is dropped from the seed, so it resolves to the
  plain file instead of a duplicate. ([#16])

### Fixed
- `generate()` uploaded a zero-byte file and recorded it in the manifest as a
  success when an optimizer was killed mid-write, leaving `asset()` to serve a
  broken URL for the life of the manifest. The encode is now rejected when it
  produces no bytes: nothing is uploaded, no entry is written, `null` is returned
  and a warning is logged. ([#14])

### Changed
- Raised minimums: `spatie/image` `^3.9.6`, and `spatie/image-optimizer` `^1.10`
  moved from `suggest` into `require`. ([#16])

## [1.2.0] — 2026-07-11

### Added
- `imagetools:generate` now pre-generates images requested from a Laravel
  filesystem disk via `ImageTools::disk('s3')->asset('assets/hero.jpg?w=800')`.
  The scanner detects a literal `disk('x')` hop before `asset()` (facade, `app()`
  and `App::make()` accessors) and carries the source disk through to generation;
  wanted entries are de‑duplicated by their canonical seed. Only explicit, literal
  image paths are pre‑generated — dynamic arguments and non‑literal `disk($var)`
  hops are skipped by design. ([#12])

## [1.1.0] — 2026-06-09

### Added
- Read the source image from any Laravel filesystem disk via a fluent
  `ImageTools::disk('s3')->asset('assets/hero.jpg?w=800')`. The original is
  streamed to a temporary local file for processing; the source disk participates
  in the canonical identity so the same path from different disks never collides. ([#8])
- Deferred (queued) generation via a truthy `queue` query flag — `asset()` returns
  the final, deterministic URL immediately and dispatches a `GenerateImageJob`
  (`ShouldBeUnique`) instead of generating in‑request. New config:
  `queue_connection`, `queue_name`, `unique_for`. ([#4])

### Fixed
- `asset()` and `generate()` could derive different manifest keys when the query
  carried options outside the supported schema, causing a cache miss on every call
  and a broken URL. Both paths now share a single canonicalization. ([#3])

### Internal
- Split the `ImageTools` god class into injected collaborators —
  `Support\Manifest`, `Support\PathResolver`, `Support\SourceReader` — wired via the
  service provider. No behaviour change. ([#9])
- Tests for the `imagetools:generate` / `imagetools:clear` commands; CI matrix
  (PHP 8.2–8.4 × Laravel 12/13) + Pint workflow; Dependabot. ([#4])
- S3 integration test against MinIO (PHPUnit group `s3`) + dedicated CI job. ([#6])

## [1.0.3] — 2026-05-20
### Added
- `symfony/finder` `^8.0` support. ([#2])

## [1.0.2] — 2026-05-20
### Added
- Laravel 13 support. ([#1])

## [1.0.1] — 2025-11-21
### Fixed
- Filename generation logic.

## [1.0.0] — 2025-11-05
- Initial release.

[Unreleased]: https://github.com/isap-ou/laravel-imagetools/compare/1.3.0...main
[1.3.0]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.3.0
[1.2.0]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.2.0
[1.1.0]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.1.0
[1.0.3]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.0.3
[1.0.2]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.0.2
[1.0.1]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.0.1
[1.0.0]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.0.0
[#1]: https://github.com/isap-ou/laravel-imagetools/pull/1
[#2]: https://github.com/isap-ou/laravel-imagetools/pull/2
[#3]: https://github.com/isap-ou/laravel-imagetools/pull/3
[#4]: https://github.com/isap-ou/laravel-imagetools/pull/4
[#6]: https://github.com/isap-ou/laravel-imagetools/pull/6
[#7]: https://github.com/isap-ou/laravel-imagetools/pull/7
[#8]: https://github.com/isap-ou/laravel-imagetools/pull/8
[#9]: https://github.com/isap-ou/laravel-imagetools/pull/9
[#12]: https://github.com/isap-ou/laravel-imagetools/pull/12
[#14]: https://github.com/isap-ou/laravel-imagetools/issues/14
[#16]: https://github.com/isap-ou/laravel-imagetools/pull/16
