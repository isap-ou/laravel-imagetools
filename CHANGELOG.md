# Changelog

All notable changes to `isapp/laravel-imagetools` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org).

## [Unreleased]

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

[Unreleased]: https://github.com/isap-ou/laravel-imagetools/compare/1.1.0...main
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
