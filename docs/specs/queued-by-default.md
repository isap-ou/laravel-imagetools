# Spec: queued generation by default, with the original as the fallback (#15)
Status: Implemented

## Context & Goal

Line references in this section are to `main` at 11cb21c, before this change.

On a manifest miss, `asset()` calls `generate()` inside the web request
(`src/ImageTools.php:113`). Each call reads the source again (a disk source is copied to a temp
file, `src/Support/SourceReader.php:38`), decodes it, runs the optimizer binaries (up to 60 s
each, `vendor/spatie/image-optimizer/src/OptimizerChain.php:20`) and uploads the result. A page
with many new images in several sizes does all of this before it sends the HTML.

The paths come from a CMS, so `imagetools:generate` cannot find them at deploy time. The
symptoms are a slow first response (TTFB) and requests that die with a 504. After a 504, a
reload starts the same work again in a second request, because nothing locks one derivative
across requests.

`queue=1` already moves the encode to a worker, but three things stop it from being the fix:
every call must add the flag, the returned URL gives a 404 until the worker is done, and the job
is never unique — `Bus::dispatch()` skips the `ShouldBeUnique` lock
(`vendor/laravel/framework/src/Illuminate/Foundation/Bus/PendingDispatch.php:202-210`), so each
render queues the job again.

Goal: an installation can move every encode off the request path with config alone. On a miss,
the first visitor gets the original image, and a worker makes the derivative once. The next
render gets the derivative.

## Functional requirements

- **FR-1** New config key `queue` (env `IMAGE_TOOLS_QUEUE`), default `false`. When it is `true`,
  every `asset()` miss is queued. A `queue` flag in the query overrides the config in both
  directions: `queue=1` queues, `queue=0` generates synchronously. With no flag, the config
  decides. `queue` stays out of the seed.
- **FR-2** New config key `queue_fallback` (env `IMAGE_TOOLS_QUEUE_FALLBACK`), default
  `original`. It sets what `asset()` returns on a queued miss:
  - `original` — the public URL of the unprocessed source (FR-3), or `''` when the source has
    none.
  - `none` — `''`.
  - `derivative` — the URL of the derivative, which gives a 404 until the worker is done (the
    1.4.0 behaviour of `queue=1`).

  *(Amended after review, twice.)* Any other value throws `InvalidArgumentException` that names
  the value, before anything is dispatched. The value is checked for every queued miss, also on a
  `sync` connection (FR-5), so a wrong value fails in a test suite too.
- **FR-3** `SourceReader::publicUrl(string $filepath, ?string $sourceDisk = null): ?string` returns
  the public URL of a source, or `null` when it has none:
  - *(Amended after review, twice.)* Both branches first decode the path (`rawurldecode`) and
    split it on `/` and `\`. A `..` segment returns `null`, because a browser resolves it to the
    parent directory. (The worker may still generate such a path when it stays inside its root.)
  - A disk source: `Storage::disk($sourceDisk)->url($filepath)`, as Laravel builds it (Laravel
    does not encode a space in a key for a disk with a `url`). A driver that cannot make URLs
    throws; the method catches it and returns `null`. It does not check that the file exists
    (one network call per render).
  - A local source: a URL only when it is a file (not a directory) under `public_path()`; the
    URL is the path relative to `public_path()`, with empty segments dropped and each segment
    `rawurlencode`d, through Laravel's `asset()` helper. Any other local path returns `null`.
- **FR-4** `dispatchGeneration()` dispatches through the `dispatch()` helper
  (`PendingDispatch`), so the `ShouldBeUnique` lock is taken. While a job for a seed is pending
  or running, a later miss queues nothing and returns the fallback again. Laravel releases the
  lock when the job ends or fails (`CallQueuedHandler.php:73, 349`); `unique_for` limits a lock
  that is lost. *(Amended after review.)* The job's unique id is
  `sha1(<manifest namespace> . '|' . <canonical seed>)`, so the lock key has a fixed length on
  every cache store. When the push throws, `dispatchGeneration()` releases the lock (Laravel does
  not). The release has no owner check: when the lock call itself threw, it can remove another
  request's lock, which costs at most one extra job, and FR-11 makes that job a no-op. `uniqueFor()` uses `unique_for` when it is above 0, else 3600: a lock of 0
  seconds never expires on Redis.
- **FR-5** When the connection that the job uses has the `sync` driver, a queued miss calls
  `generate()` directly and returns what the synchronous path returns: the URL, `null` for a
  request larger than the source, or `''` for a failure. The connection is
  `image-tools.queue_connection`, else `queue.default`.
- **FR-6** When the dispatch throws (for example, the queue backend is down), `asset()` logs a
  warning with the error and generates synchronously, as FR-5 does. The page is slow, but it
  does not fail.
- **FR-7** `GenerateImageJob::handle()` logs a warning when `generate()` returns `null`, with the
  path and the source disk. The job still ends normally, so its lock is released.
- **FR-8** A manifest hit does not change: the URL, or `null` for a request larger than the
  source.
- **FR-9** README (queued section and config list), config comments, `llms.txt`, the
  `AGENTS.md` invariant ("the `queue` flag or the `queue` config defers generation") and the
  CHANGELOG `[Unreleased]` describe the new behaviour. The release is a minor version.
- **FR-10** *(Added after review; amended after the second review.)* On a manifest miss,
  `asset()` calls `Manifest::refresh($namespace)` once and checks again. `refresh()` never writes.
  - `load()` reads with a plain `require`, which can return an older opcache copy (PHP-FPM with
    `opcache.validate_timestamps=0`, or inside `revalidate_freq`). So `load()` records no
    signature.
  - The first `refresh()` after `load()` compares the file's size with the length of the text
    that `put()` would write for the entries in memory (`render()`). When they are equal, it
    records the file's signature and reads nothing. When they differ, it reads the file again.
    A change that keeps exactly the same length is not seen until the next write.
  - A later `refresh()` compares the file's signature (inode, mtime and size, after
    `clearstatcache`) with the one this process recorded when it last read or wrote the file.
    When they differ, it reads the file again.
  - The read in `refresh()` is `read()`, which drops the opcache copy first.
  - So a worker's entry reaches PHP-FPM (also with `validate_timestamps=0`) and a long-lived
    process (Octane, a queue worker that renders mail) on the next miss. A hit found this way
    returns its URL or `null` and queues nothing.
- **FR-11** *(Added after the second review.)* New public `ImageTools::has(string $path,
  string $manifest = 'default'): bool`: `refresh()`, then whether the manifest has an entry for
  the canonical seed of `$path` (with this instance's source disk). `GenerateImageJob::handle()`
  returns before `generate()` when `has()` is true, so a job that a stale view or a race
  dispatched costs no encode.

## Acceptance criteria

- **AC-1** `queue` config `true`, no flag, a miss → one `GenerateImageJob` is dispatched.
- **AC-2** `queue` config `true`, `queue=0` → no job; the file is generated synchronously.
- **AC-3** `queue` config `false`, `queue=1` → one job.
- **AC-4** `queue` config `false`, no flag → no job; synchronous generation (the default path
  does not change).
- **AC-5** `original`, a disk source (`disk('s3')` on a fake disk) → the fake disk's URL of the
  source path.
- **AC-6** `original`, a local source under `public/` → the public URL of that file.
- **AC-7** `original`, a local source outside `public/` → `''`.
- **AC-8** `none` → `''`.
- **AC-9** `derivative` → the URL of the stored derivative (the 1.4.0 `queue=1` result).
- **AC-10** Two misses of one seed while the first job is pending → one job is dispatched.
- **AC-11** A `sync` connection → the derivative URL, and the file exists; for a request larger
  than the source → `null`.
- **AC-12** A dispatch that throws → a warning is logged, and `asset()` returns the synchronous
  result (the URL, and the file exists).
- **AC-13** The job for a missing source → a warning is logged, no manifest entry.
- **AC-14** The seed and the stored filename are the same with `queue` on and off.
- **AC-15** Full suite green (GD locally; Imagick and S3 in CI). `vendor/bin/pint --test` clean.
- **AC-16** *(Added after review; AC-16 … AC-24.)* An unknown `queue_fallback` → an
  `InvalidArgumentException` naming the value, and no job is dispatched.
- **AC-17** `publicUrl()` with `../x`, `..\x` or `%2e%2e/x` on a disk → `null`.
- **AC-18** `publicUrl()` for `public/`, a directory, or a missing file → `null`.
- **AC-19** `publicUrl()` for `public/images/a b.png` → a URL ending in `images/a%20b.png`; a
  path with `//` gives a URL without an empty segment.
- **AC-20** `uniqueId()` is 40 characters for a very long path; `uniqueFor()` is 3600 when
  `unique_for` is 0.
- **AC-21** A push that throws, followed by a failed in-request generation → the next miss of
  the same seed dispatches a job (the lock was released).
- **AC-22** A second `Manifest` instance writes an entry → `refresh()` makes it visible to the
  first; an unchanged file is not read again.
- **AC-23** A queued miss whose entry another process has written → `asset()` returns the
  derivative URL and dispatches nothing.
- **AC-24** `queue_connection` null with `queue.default` `sync` → a queued miss returns the
  generated URL (FR-5 through the default connection).
- **AC-25** *(Added after the second review; AC-25 … AC-28.)* A `Manifest` whose `load()` got an
  older copy than the file → the first `refresh()` reads the file and sees the newer entry.
- **AC-26** A job whose entry another process has written → no file is generated.
- **AC-27** A `sync` connection with an unknown `queue_fallback` → `InvalidArgumentException`.
- **AC-28** `publicUrl('public///images/x.png')` → a URL without `//` in its path.

## Non-goals

- A signed `temporaryUrl()` for a private bucket. For a private disk, `url()` gives a URL that
  does not show the image (403 or 404) until the worker is done. The README says so.
- An on-demand route that makes a derivative when the browser asks for it.
- Encoding faster inside the job: one decode for several sizes of one source, one copy of a
  disk source, no optimizer in some cases. A later change can add this.
- Reusing a file that already exists at the stored name when the manifest lost its entry (a
  per-release manifest after a deploy). It would skip the "larger than the source" check.
- A persistent "pending" or "failed" marker in the manifest. The unique lock is the only
  pending state.

## Edge cases

- **Larger than the source**: at a queued miss, the size of the source is not known yet. With
  `original`, the first render gets the original; the worker then records `null`, and later
  renders return `null`. The README documents this in its queued section and in
  Troubleshooting.
- **`srcset`**: all widths of one source share one original URL, so the browser downloads it
  once.
- **`<picture><source type="image/avif">`** with an original JPEG URL: the browser picks the
  `<source>` by its `type` and gets a JPEG. It shows: checked in Chromium 152, where a
  `<source type="image/avif">` and a `<source type="image/webp">` with a JPEG URL both rendered
  the JPEG and did not fall back to the `<img>`. Safari and Firefox are not checked.
- **No worker running**: the lock stays up to `unique_for`, and renders keep returning the
  fallback. Nothing breaks; the images stay unprocessed.
- **A missing source**: each render after the job ends queues it again. The job is cheap (it
  stops at the existence check) and logs a warning each time.
- **The unique lock needs a cache store with atomic locks** (`file`, `redis`, `database`,
  `memcached`). With the `array` store, the lock lives in one process only.
- **Behaviour change for `queue=1` users**: a miss returned the derivative URL in 1.4.0. With the
  new default `original`, it returns the original URL, or `''` for a local source outside
  `public/`. `queue_fallback=derivative` restores the old result. The CHANGELOG states it.
- *(Added after review.)* **Web and workers on different hosts**, each with its own manifest
  file: the web never sees the worker's entry, and FR-10 cannot help. With `original`, the page
  shows the original with no end, and each render after a job ends queues a re-encode. Queued
  mode needs one shared manifest file (`manifest_path` on shared storage), or
  `queue_fallback=derivative`. The README says so.
- **Output that is rendered once** (queued mail, full-page or CDN caches) keeps the fallback;
  such calls should use `queue=0`. The README says so.
- **What `original` exposes**: the HTML gets the source URL (host, bucket, directories, file
  name). For a source that is readable but linked nowhere, it links the full-size file with its
  metadata; the derivative does not always strip metadata either (Imagick without optimizer
  binaries keeps EXIF). The README recommends `none` or `derivative` for private sources and
  user uploads.
- **A job that fails or times out** (a query that fails validation, an encode longer than the
  worker's `--timeout`) releases its lock, so each later render queues it again; the failures
  show in `failed_jobs`, not on the page. Accepted with the "no failed marker" Non-goal; the
  README says so.
- **A disk without a `url`** (the Laravel 11+ `local` disk) gives `/storage/<path>`, which is a
  404 or 403, or another public file with the same path. Accepted with the private-disk
  Non-goal.

## Open questions

None.
