# Spec: release 1.6.0 — encode control, safe requests, medialibrary, orphan prune
Status: Implemented

## Context & Goal

The hemostatic.com.ua pilot uses ImageTools for the slider srcset. Its originals are on R2, it runs
in queue mode, and it uses GD on purpose. In 1.5.0 the encode always uses Imagick when the extension
is loaded (`vendor/spatie/image/src/Image.php:33`). The spatie default chain also encodes lossy WebP
and AVIF again (`src/ImageTools.php:404`, `OptimizerChainFactory.php:56-70`). A failed encode gives
a 500 error, and a lost manifest makes every file encode again. Files without a manifest entry stay
on the disk for ever.

Goal: close these gaps, add a call for medialibrary, and delete files that the manifest does not
know. Existing filenames do not change.

## Functional requirements

- **FR-1** Config key `driver` (`IMAGE_TOOLS_DRIVER`, default `null`). With `null`, the package keeps
  `new Image($path)`. With a value (`gd`, `imagick`, `vips`, or an `ImageDriver` class), it uses
  `Image::useImageDriver($driver)->loadFile($path)`. The source is loaded in one place, `loadImage()`.
- **FR-2** Query option `optimize`. It accepts the same spellings as `lossless` and is on by default.
  In the seed, an off value becomes `optimize=0` and an on value drops out. `PathResolver::OPTION_KEYS`
  gets `optimize`. Any other value fails validation.
- **FR-3** With `optimize` off, no optimizer runs. `lossless` still sets the Imagick WebP lossless option.
- **FR-4** Config key `optimizers` (default `null`). With `null`, the package uses
  `OptimizerChainFactory::create()`, as today. An array `class => args` gives a chain of exactly those
  optimizers, in that order. With `[]`, no optimizer runs. The package builds the chain itself
  (`OptimizerChainFactory.php:30-33, 80-91`).
- **FR-5** A lossless WebP on Imagick keeps the cwebp `-lossless` chain, also when `optimizers` is set.
- **FR-6** `asset()` catches a `Throwable` from the in-request `generate()`. It calls `report($e)` and
  returns `''`. An unknown `queue_fallback` still throws. `generate()`, `GenerateImageJob` and the
  commands still throw. *(Amended in step 3 and after review:)* Laravel's handler never reports a
  `ValidationException` (`Foundation/Exceptions/Handler.php:161`), and a report names no image. So
  for every caught exception `asset()` also logs a warning with the path, the disk, the exception
  class and the message, plus the validation errors for a `ValidationException`.
- **FR-7** *(amended)* In `generate()`, the source loads and the size check runs first. Then, while it
  holds the manifest lock, the package reads the manifest file again. When the file has no entry for
  the seed and the file at `storedFile()` exists on the output disk with a size above 0, it writes the
  entry and returns it. It skips the encode and the upload. A new `Manifest::putIfAbsent()` does this.
  In every other case the encode runs as today. An oversize request still goes to `recordOversize()`.
  *(Amended after review:)* A first check runs without the lock, and the lock is taken only when
  the file is there, so a real miss holds no lock during disk calls. A `FilesystemException` from
  the check logs a warning and counts as "not stored", so the encode runs.
- **FR-8** Config key `put_options` (array, default `[]`). The package passes it as the 4th argument
  of `putFileAs()`.
- **FR-9** *(new)* `ImageTools::media(object $media, string $query = '', string $manifest = 'default'): ?string`.
  The call is the same as `disk($media->disk)->asset($media->getPathRelativeToRoot() . '?' . $query)`,
  and it returns what `asset()` returns. The object must have a `disk` attribute and the methods
  `getPathRelativeToRoot()` and `getKey()`. A spatie/laravel-medialibrary 11 `Media` has them
  (`Media.php#L128` at tag 11.23.10). Without them, the call throws `InvalidArgumentException`. The
  package gets no new dependency; `composer.json` gets a `suggest` entry.
- **FR-10** *(new)* An entry written for a `media()` call records `media_id` (`$media->getKey()`). This
  covers the in-request path, the reuse path, an oversize request and the queued job.
  `GenerateImageJob` carries the id: it gets a new last constructor parameter with the default `null`.
  `imagetools:regenerate` keeps the recorded `media_id`. The id is not part of the seed, so `asset()`
  and `media()` for the same file share one entry. An entry that `asset()` wrote first has no `media_id`.
  *(Amended after review:)* `GenerateImageJob::$mediaId` is a plain property with the default `null`,
  so a job that 1.5 queued still runs after `unserialize()`. `withMediaId()` is `@internal`. A Media
  file name with a `?` gives `''` and a warning, because the first `?` starts the query.
- **FR-11** *(new)* Command `imagetools:prune` with `--dry-run` and `--force`. It deletes orphans. An
  orphan is a file directly in `image-tools/` on `config('image-tools.disk')` that matches
  `^image-tools/[^/]+--[0-9a-f]{10}\.[A-Za-z0-9]+$` and that no entry in the default manifest
  references by disk and path. The command reads the listing with `listContents('image-tools', false)`
  and takes `lastModified()` from it.
- **FR-12** *(new)* The command keeps a file whose `lastModified()` is less than 1 hour old or unknown.
  That covers the time between an upload and its manifest write (`ImageTools.php:238` → `:248`).
- **FR-13** *(new)* Guards. Each one makes the command exit 1 and delete nothing:
  - an empty default manifest;
  - a manifest whose realpath is under `base_path('bootstrap/cache')`, unless `--force` is given;
  - more than 50% of the matching files are orphans, unless `--force` is given. *(Amended after
    review:)* with `--dry-run` this guard prints a warning and the list, because nothing is deleted.

  First the command prints the manifest path and its entry count.
- **FR-14** *(new)* For each orphan, the command takes the manifest lock with `Manifest::whileLocked()`
  and reads the manifest file again. It deletes the file only when no entry references it yet.
  `whileLocked()` refuses to run without a lock. Unlike `put()`, it has no fallback without a lock.
  Code under the lock never calls `put()`, because a nested `flock` in one process blocks for ever.
- **FR-15** *(new)* A delete can throw, or it can return `false`. In both cases the command logs a
  warning, prints an error line and counts a failure. The run continues. At the end it prints a
  summary line: listed, orphans, deleted, kept as too new, failed. It exits 1 when one or more deletes
  failed. `--dry-run` prints each orphan and deletes nothing. *(Amended after review:)* Under the lock
  the command also reads the file's `lastModified()` again and skips a file that changed since the
  listing (a worker encoded it again). A skipped file is printed with its reason. The summary is:
  listed, orphans, deleted, skipped, kept as too new, kept with no time, failed. A listing failure and
  a lock failure are logged as a warning too.
- **FR-18** *(added after review)* `imagetools:clear` deletes each file inside `whileLocked()`, after
  it reads the manifest again, and keeps a file that an entry references again. Between the manifest
  delete and the file delete, a request can miss and adopt the file (FR-7). When the lock cannot be
  taken, the file is deleted without the check, as in 1.5.
- **FR-19** *(added after review)* A `putFileAs()` that returns `false` logs a warning with the path,
  the disk, the stored name and the `put_options` keys. The output disk can be `null` (Laravel's
  default disk), as in 1.5.
- **FR-16** A `LICENSE` file with the MIT text and the line
  `Copyright (c) 2025 ISAPP (self-employed, Belgium) and ISAP OÜ (Estonia)`.
- **FR-17** These docs describe all of the above: the README, the CHANGELOG `[1.6.0]` entry, the config
  comments, the AGENTS.md invariants, `llms.txt` and the Facade docblock. The README warns about three
  cases. First, cached HTML (a full-page cache or a CDN) can still reference deleted files. Second,
  several hosts or extra namespaces on one output disk lose their files. Third, a per-release manifest
  is unsafe.

## Acceptance criteria

- **AC-1** `driver=gd` → the encode runs with `gd`. `null` → the auto choice. An unknown driver →
  `InvalidImageDriver` from `generate()`.
- **AC-2** The seed of `?optimize=0` differs from the seed without the flag. `?optimize=1` and
  `?optimize=true` give the same seed as no flag.
- **AC-3** `optimize=0` → no chain reaches the encode, and the file has its own name.
- **AC-4** The default → the spatie default chain (7 optimizers). `optimizers` set → exactly the given
  classes with their args. `[]` → an empty chain.
- **AC-5** Imagick: `lossless=1` with `optimizers` set → the cwebp lossless chain.
  `optimize=0&lossless=1` → the lossless option is set and no chain runs.
- **AC-6** `optimize=maybe` → `ValidationException` from `generate()`.
- **AC-7** `asset()` with a query that fails validation → `''` and a logged warning. `asset()` with an
  encode that throws → `''`, and the exception is reported. `generate()` and the job still throw.
- **AC-8** A file at the stored name and no entry → no encode, the entry is written. An empty file → an
  encode. An oversize request → null, and the file is deleted. An entry that exists → an encode.
- **AC-9** `put_options = ['visibility' => 'private']` → the stored file is private. The default → public.
- **AC-10** `media($stub, 'w=8')` on a disk → the same URL and seed as `disk(...)->asset(...)`, and the
  entry has `media_id`. A queued `media()` miss → the job writes `media_id`. `regenerate --all` keeps
  `media_id`. An object without the methods → `InvalidArgumentException`.
- **AC-11** `prune` deletes an old orphan. It keeps a referenced file, a new file, and a file whose name
  does not match.
- **AC-12** Each guard → exit 1 and nothing deleted: an empty manifest, a manifest under
  `bootstrap/cache`, more than 50%. With `--force` the last two pass.
- **AC-13** `--dry-run` → the orphans are listed, and nothing changes.
- **AC-14** A delete that throws, and a delete that returns `false` → a warning, the run continues, exit 1.
- **AC-15** `whileLocked()` runs the callback with the entries from the file and refuses without a lock.
  `putIfAbsent()` writes nothing when the key exists.
- **AC-17** *(added after review)* A 1.5 job payload without `mediaId` → `unserialize()` and
  `handle()` run, the entry is written. A disk whose `fileExists()` throws → a warning and an encode.
  An upload that returns `false` → a warning. A `null` output disk → the default disk. A Media file
  name with `?` → `''` and a warning. `optimize=` → the file of `optimize=0`.
- **AC-18** *(added after review)* `imagetools:clear` keeps a file that a request adopted after the
  manifest delete. `prune` skips a file that changed since the listing, ignores a subdirectory file
  and a name with another hex length, prints the manifest line, and lists the orphans with
  `--dry-run` when more than half are orphans.
- **AC-16** The full suite and `vendor/bin/pint --test` are green. The shipped config has
  `driver === null`, `optimizers === null` and `put_options === []`.

## Non-goals

- No prune by rules: no `--missing-source`, `--unwanted`, `wanted()`, presets or usage tracking.
  `media_id` is recorded only so that a later prune can use it.
- No `forgetMedia()`. No dependency on medialibrary.
- `driver`, `optimizers`, `put_options` and `media_id` are not part of the seed.
- `prune` reads only the default manifest. The job's failure behaviour does not change. *(Amended:)*
  `imagetools:clear` changes only as FR-18 says.

## Edge cases

- A reused file keeps the bytes of its old encode. After a change to `driver` or `optimizers`, run
  `regenerate --all`.
- The reuse path still loads the source, because the size check needs it.
- `optimize=` (an empty value) counts as off, by the same `FILTER_VALIDATE_BOOLEAN` rule as `queue`
  and `lossless`.
- `media()` with an empty query gives the original's seed, as `asset('path')` does.
- An entry with `path === null` references no file.
- *(Added after review)* On a local disk the upload writes in place, so a worker killed mid-upload
  can leave a partial file with more than 0 bytes. The next miss adopts it, and `regenerate` keeps
  it. Accepted; delete the file, or run `regenerate --all`.
- *(Added after review)* An optimizer that fails is swallowed by spatie/image-optimizer's
  `DummyLogger`, as in 1.5. Not changed: a real logger logs each optimizer run.
- *(Added after review)* The check of FR-7 holds the lock during disk calls when a file is there.
  The README asks for an HTTP timeout on S3 and R2 disks.

## Open questions

None.
