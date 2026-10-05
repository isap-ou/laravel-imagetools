# Changelog

All notable changes to `isapp/laravel-imagetools` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org).

## [Unreleased]

### Added
- `queue` config key (`IMAGE_TOOLS_QUEUE`, default `false`). When it is `true`,
  every `asset()` miss is generated in a queued job, with no flag in the query.
  A `queue` flag still overrides it per call: `queue=1` queues, `queue=0`
  generates in the request. ([#15])
- `queue_fallback` config key (`IMAGE_TOOLS_QUEUE_FALLBACK`, default
  `original`). It sets what `asset()` returns for a queued miss: `original` —
  the public URL of the unprocessed source, or `''` when it has none; `none` —
  `''`; `derivative` — the URL of the derivative, a 404 until the worker is
  done. Any other value throws before the job is queued. ([#15])
- `Manifest::refresh()` reads a manifest file again when it changed since this
  process read or wrote it. On a miss, `asset()` calls it once, so an entry that
  a worker wrote reaches PHP-FPM (also with `opcache.validate_timestamps=0`) and
  long-lived processes without a reload. ([#15])
- `ImageTools::has($path)` says whether the manifest has an entry for a request,
  after it reads a changed manifest again. A queued job uses it and returns
  before it encodes when its entry exists already. ([#15])

### Changed
- A queued miss returns the original's URL now, not the derivative's. A local
  source outside `public/` has no URL, so it returns `''`. Set
  `queue_fallback` to `derivative` for the old result. ([#15])
- `original` puts the source's URL (host, bucket, file name) into the HTML and
  links the full-size file with its metadata. For private sources and user
  uploads, set `queue_fallback` to `none` or `derivative`. Queued mode also needs
  one manifest file that the web and the workers share. ([#15])
- A queued miss on a connection with the `sync` driver is generated in the
  request, and `asset()` returns the real result. A test that uses
  `Bus::fake()` with `QUEUE_CONNECTION=sync` therefore sees no dispatch; set a
  queueing connection in `image-tools.queue_connection` for such a test. ([#15])
- When the dispatch of the job fails, `asset()` logs a warning and generates in
  the request, instead of throwing. ([#15])

### Fixed
- `GenerateImageJob` was never unique. `Bus::dispatch()` skips the
  `ShouldBeUnique` lock, so each render of a pending image queued the job
  again. The job is now dispatched through the `dispatch()` helper, and its
  unique key is a hash of the canonical seed: `?w=800&queue=1` and
  `?queue=1&w=800` share one lock, and the key fits a database cache store.
  A push that fails releases the lock. A `unique_for` of 0 or less falls back
  to 3600 seconds, because a lock of 0 seconds never expires on Redis. ([#15])
- A queued job that could not generate its image (for example, a missing
  source) ended without a trace. It now logs a warning. ([#15])

## [1.4.0] — 2026-09-25

### Added
- `Manifest::clear()` reads the default manifest and deletes its file under the
  manifest lock. It returns the entries that the file held. `imagetools:clear`
  uses it. ([#21])

### Changed
- The output of existing queries changes: a resize by `w` or `h` without `fit` no
  longer enlarges a source that is smaller than the request. Such a request stores
  no file: the manifest records it with `'path' => null`, and `asset()` returns
  `null`, so the template can leave the image out. `fit` is not affected. The new
  `allow_upscale` config key (`IMAGE_TOOLS_ALLOW_UPSCALE`, default `false`) brings
  back the old behaviour. ([#21])
- `asset()` now returns `?string`. Code that passes its result to a `string`
  parameter must handle `null`. `asset()` still returns `''` when `generate()`
  returns `null`, for example for a missing source. `generate()` returns
  `['path' => null, 'disk' => null]` for such a request; `null` still means a
  failure. ([#21])

### Fixed
- `Manifest::put()` wrote its in-memory copy of the whole manifest back to the
  file. A long-lived process, such as a queue worker, could restore entries that
  another process had changed or removed since — including entries to files that
  were deleted. Each write now takes a lock (`<manifest>.lock`) and starts from
  the file as it is on disk. `imagetools:clear` reads and deletes the manifest
  under the same lock. The lock also works on a lock file that another user
  created; when no lock can be taken, the write goes ahead without it and a
  warning is logged. ([#21])
- A namespace loaded with `loadManifest($path)` was written into the default
  manifest file. It is now written to its own file. ([#21])

### Upgrading
- Existing entries keep their enlarged files, because the key and the filename do
  not change. `php artisan imagetools:regenerate --all` applies the new rule: an
  entry larger than the source gets `'path' => null`, and its enlarged file is
  deleted. The command lists each such entry and counts it as
  `larger than the source`. Entries without a recorded source are skipped.
  `imagetools:generate` deletes the files that the manifest references and the
  manifest itself, then rebuilds by the new rule. A build pipeline that runs it
  applies the rule on the next deploy. A later change to `allow_upscale` needs the
  same step. ([#21])
- The enlarged file is deleted even when the manifest no longer holds its entry
  (a per-release `bootstrap/cache`). Pages cached before the run still point to
  the deleted files: clear full-page and CDN HTML caches after it, and reload
  PHP-FPM when `opcache.validate_timestamps=0`. ([#21])
- Restart long-lived processes (`queue:restart`, `octane:reload`) after the run:
  they keep the manifest in memory. When several hosts keep their own manifest on
  one shared output disk, run the command on each host. ([#21])
- A delete that the disk refuses is logged as a warning and does not fail the
  request. This covers a disk that throws and a disk whose `delete()` returns
  `false` (the `'throw' => false` default). ([#21])
- Writes create a lock file next to the manifest, by default
  `bootstrap/cache/image-tools.php.lock`. The users that run the web server, the
  queue workers and the deploy must be able to create it, or at least to open it
  when it exists. Otherwise each write logs a warning and goes ahead without the
  lock. ([#21])
- A `null` entry stays `null` when its source is replaced by a larger image; run
  `imagetools:regenerate --all` after you replace a source. ([#21])
- A queued request larger than the source returns its URL before the worker reads
  the source. The worker stores no file, so that URL returns 404; calls after the
  worker has run return `null`. ([#21])

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

[Unreleased]: https://github.com/isap-ou/laravel-imagetools/compare/1.4.0...main
[1.4.0]: https://github.com/isap-ou/laravel-imagetools/releases/tag/1.4.0
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
[#15]: https://github.com/isap-ou/laravel-imagetools/issues/15
[#16]: https://github.com/isap-ou/laravel-imagetools/pull/16
[#21]: https://github.com/isap-ou/laravel-imagetools/issues/21
