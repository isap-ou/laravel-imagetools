# Spec: stop `w`/`h` resizes from enlarging a smaller source (#21)
Status: Implemented

## Context & Goal

`generate()` resizes by `w` or `h` with Spatie's default `[PreserveAspectRatio]`
(`src/ImageTools.php`, `vendor/spatie/image/src/Image.php`). That default enlarges a source
that is smaller than the target. The result is a heavier file with no extra detail. On a real
site, 75 of 99 derivatives were wider than their source.

Goal: by default the package does not enlarge. An oversize request stores no file, and
`asset()` returns `null` for it, so the caller can leave it out of the HTML. A config key
keeps the old behaviour available.

## Functional requirements

- **FR-1** New config key `allow_upscale` (env `IMAGE_TOOLS_ALLOW_UPSCALE`), default `false`.
- **FR-2** A request is *oversize* when it has no `fit` and either (a) it has `w` and `w` > source
  width, or (b) it has `h` without `w` and `h` > source height. The source size is the loaded
  image's size after EXIF auto-rotation (`$image->getWidth()/getHeight()`).
- **FR-3** With `allow_upscale=false`, `generate()` for an oversize request stores no file. It
  writes the entry `['path' => null, 'disk' => null, 'source' => $path, 'source_disk' => …]` and
  returns `['path' => null, 'disk' => null]`. `null` still means failure.
- **FR-4** *(amended after review, twice)* In the FR-3 case, `generate()` first writes the
  null-path entry, then deletes the file at the deterministic stored name
  (`PathResolver::storedFile()`) on the configured disk, and also the file of the previous
  manifest entry when that entry records a different disk/path. A failed delete is caught and
  logged as a warning; it does not fail `generate()` or `asset()`.
- **FR-5** `asset()` returns `null` for an entry with `path === null`, on a manifest hit and right
  after a synchronous generation. Return type becomes `?string`. Failures still return `''`.
- **FR-6** With `allow_upscale=true`, `w`/`h` enlarge as today.
- **FR-7** The `fit` branch does not change.
- **FR-8** `imagetools:clear` skips entries with `path === null`. It still deletes the manifest.
- **FR-9** `imagetools:regenerate` counts an entry with `path === null` as healthy. With `--all`
  it rebuilds the entry by the current rules.
- **FR-10** README, CHANGELOG `[Unreleased]`, config comment, AGENTS.md invariants, llms.txt and
  the Facade docblock describe the new behaviour. *(Extended after review:)* they also cover
  FR-11, the cache warning for deleted enlarged files, and the queued-mode wording. The release
  is a minor version (`feat:`); the CHANGELOG states the output and return-type change clearly,
  with no BREAKING tag.
- **FR-11** *(added after review, amended in round 2)* `Manifest::put()` takes a blocking
  `flock(LOCK_EX)` on `<realpath of the manifest, else its path>.lock`. It opens the lock file for
  writing and falls back to read-only when it has no write permission (`@fopen 'c' ?: @fopen 'r'`;
  `flock(2)` locks a read-only handle too). When no lock can be taken, it logs a warning and
  writes without the lock — a write that worked before this change never fails because of the
  lock. Under the lock it reads the manifest file again from disk (opcache copy invalidated first),
  applies the one change, writes atomically with `Filesystem::replace()` and invalidates opcache.
  A writer never writes back entries from an older in-memory copy. A non-default namespace (its
  key is its file path, as `load($path)` defines it) is written to its own file, not to the
  default manifest.
- **FR-12** *(added after review)* `.gitattributes` excludes `/docs` from the dist archive;
  `composer.json` require-dev declares `ext-gd` (the test fixtures are drawn with GD); the lint
  workflow installs `gd`.
- **FR-13** *(added in round 2)* `Manifest::clear()` reads the default manifest and deletes the
  file under the same lock, and returns the entries. `imagetools:clear` uses it, then deletes the
  files.

## Acceptance criteria

- **AC-1** Default config, `?w=` above source width → no file on disk, entry `path` null, `asset()` → `null`.
- **AC-2** `?h=` above source height → same as AC-1.
- **AC-3** `?w=` below source width → file at `w`, aspect ratio kept.
- **AC-4** `?w=` equal to source width → file at source width (boundary, not oversize).
- **AC-5** `fit=crop` with `w`×`h` above the source → file of exactly `w`×`h`.
- **AC-6** `allow_upscale=true`, `?w=` above source → file at `w` (enlarged).
- **AC-7** An existing entry with a file becomes oversize on `generate()` → the file is deleted, entry `path` null.
- **AC-8** `asset()` on an entry with `path` null → `null`, and it does not call `generate()`.
- **AC-9** `GenerateImageJob` for an oversize request → entry `path` null, no file.
- **AC-10** `imagetools:clear` with a `path`-null entry → other files and the manifest deleted, no error.
- **AC-11** `imagetools:regenerate` → a `path`-null entry is healthy; with `--all` and `allow_upscale=true` it stores an enlarged file.
- **AC-12** Full suite green (GD locally; Imagick and S3 in CI). `vendor/bin/pint --test` clean.
- **AC-13** No manifest entry, a file at the stored name of an oversize request → deleted.
- **AC-14** Manifest B is loaded; A then writes S→null; B writes T → the file holds S→null and T.
- **AC-15** `put()` on a namespace loaded from another file writes that file; the default manifest
  does not change.
- **AC-16** `asset()` returns `''` when generation fails (missing source).
- **AC-17** `disk('s3')` oversize request → entry keyed with `s3:`, `source_disk` `s3`, `asset()`
  null, no temp source copy left.
- **AC-18** `?h=` below the source height stores a file at that height, aspect ratio kept.
- **AC-19** A lock file without write permission (chmod 0444) → `put()` still writes the entry.
- **AC-20** A disk whose `delete()` throws → an oversize `generate()` still returns the null-path
  array, the entry is written, a warning is logged.
- **AC-21** `Manifest::clear()` returns the entries and removes the file; the `imagetools:clear`
  tests stay green.
- **AC-22** The shipped config file has `allow_upscale === false`.
- **AC-7b** An entry recording a different path than the stored name → that file is deleted.
- AC-2 is checked in full: for an `h`-only oversize request the entry `path` is null and
  `asset()` returns null.

## Non-goals

- No `srcset()` helper, no recorded source width. When all large widths drop out, no
  candidate at the source width is offered. The caller handles this gap.
- No per-request opt-in flag for enlargement.
- `allow_upscale` is not part of the seed. Filenames and keys do not change.
- Failures keep returning `''`.
- No automatic migration of existing manifests.
- The manifest lock has no timeout. The `.lock` file stays next to the manifest.
- `load()` (the read in each request) is not locked; the atomic rename in `replace()` covers
  readers. A long-lived process can still READ an old copy; the docs tell users to reload
  PHP-FPM when `opcache.validate_timestamps=0`.

## Edge cases

- **Queued mode**: `asset()` returns the URL before the worker reads the source. For an oversize
  request that URL returns 404 until the page is rendered again. After that, `asset()` returns
  `null`. Accepted; the README documents it.
- Entries from older versions keep their enlarged file until `regenerate --all` (entries with a
  recorded source) or `generate` (it clears first).
- Pages cached before `regenerate --all` or `generate` point to the deleted enlarged files. The
  docs tell users to clear page caches after the run.
- A change to `allow_upscale` applies to existing entries only after `regenerate --all`. The
  same holds when a source is replaced by a larger image: its null entry stays null until then.
- Several hosts with their own manifest on one shared output disk: the command must run on each
  host, because the files it deletes are the ones the other manifests still point to. Documented.
- `w` and `h` without `fit`: the geometry uses `w` only, so only `w` decides.
- `fit=contain` and `fit=max` do not give exactly `w`×`h` (`Fit.php`). The issue's
  "fit = exact w×h" holds for `crop`, `stretch`, `fill`, `fill-max`. `fit` is not changed.
- `{{ null }}` prints nothing, but a `srcset` descriptor next to it still prints. The caller must
  check the value. The README shows the pattern.
- A caller that passes `asset()`'s result to a `string` parameter under strict types gets a
  TypeError for an oversize request. The CHANGELOG notes it.

## Open questions

None.
