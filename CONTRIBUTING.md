# Contributing to phpser

## Requirements

- PHP 8.2 or later (debug build recommended for development:
  `--enable-debug`)
- C compiler: GCC 11+, Clang 14+, or MSVC 2019+
- `phpize` and `php-config` (from `php-dev` or `php8.x-dev`)
- GNU Make (Unix) or Visual Studio (Windows)

phpser is a single-`.c` extension with no external library
dependencies.

## Bug reports

Use the [GitHub issue tracker](https://github.com/iliaal/phpser/issues).
Include:

- PHP version (`php -v`)
- phpser version (`php -r 'echo phpversion("phpser");'`)
- Operating system and compiler version
- A minimal reproducing PHP snippet (the value being serialized, the
  options passed to `phpser_unserialize` if relevant, and the
  observed vs. expected behavior)
- Any error messages, exceptions, or crash output

Before filing, try to reproduce against the latest `master` branch.

**For security issues, do not file a public issue.** See
[SECURITY.md](SECURITY.md) for the private reporting process.

## Pull requests

1. Fork and clone the repo.
2. Create a topic branch off `master`.
3. Make your changes.
4. Add or update tests in `tests/` (PHPT format — see existing files
   for examples).
5. Build and run the full suite:

   ```sh
   phpize
   ./configure --enable-phpser
   make -j$(nproc)

   TEST_PHP_EXECUTABLE=$(which php) \
     TEST_PHP_ARGS="-d extension=$(pwd)/modules/phpser.so" \
     NO_INTERACTION=1 \
     php run-tests.php tests/
   ```

6. Verify zero compiler warnings — CI treats any warning as a build
   failure — and that all PHPT tests pass.
7. Push and open a PR against `master`.

### Commit message conventions

- Short imperative subject line (≤ 72 chars): "Add foo", "Fix bar",
  "Update baz".
- Body wraps at 72 columns, explains **why** not **what**.
- No `Co-Authored-By` lines. No AI attribution.
- Audit the message against `git show --stat HEAD` before pushing —
  if the subject claims a fix is in X file, the diff had better show
  X.

### Test guidelines

- Tests use PHPT format.
- Prefer exact-byte expectations via `--EXPECT--`. Use `--EXPECTF--`
  only when output legitimately varies (line numbers in exception
  traces, object IDs).
- Round-trip tests are the baseline: `phpser_unserialize(phpser_serialize($v)) == $v`
  for every shape. Anything that doesn't round-trip needs an explicit
  reason in a comment.
- igbinary parity / regression tests live in `tests/061-igbinary-bug-regressions.phpt`.
  When fixing a bug igbinary also has, mirror the case there.
- Wire-format and adversarial-input tests live in
  `tests/060-malformed-input.phpt` and `tests/069-fuzz-refs.phpt`.
  Any new tag, prefix byte, or refcount rule needs a malformed-input
  case.

### Stub-generated arginfo

Function signatures are declared in `phpser.stub.php`. Don't hand-edit
`phpser_arginfo.h`; regenerate it from the stub:

```sh
php $PHP_SRC/build/gen_stub.php phpser.stub.php
```

(where `$PHP_SRC` is a PHP source checkout matching your target PHP
minor — the gen_stub script lives under `build/`).

### Code style

- Tab indentation, 4-space tab stops.
- Symbol prefix `phpser_` on file-static helpers; `PHP_FUNCTION` /
  `PHP_MINIT` / etc. for Zend entry points.
- Memory: use PHP's `emalloc`/`efree` at the Zend boundary;
  `zend_string_*` helpers for refcounted strings.
- No comments explaining *what* the code does — the identifiers
  already say that. Comments should explain *why* (a hidden constraint,
  workaround, or surprising behavior).
- Wire-format changes need a corresponding update to the "Wire format"
  section in `README.md`. Additive tags bump the emitted wire byte to
  `PHPSER_VERSION_V2` (`0x02`); a backwards-incompatible change needs a new
  `PHPSER_VERSION*` constant (both defined in `phpser.c`) and a decoder that
  still accepts the older byte.

### Performance changes

Benchmark before claiming a perf win. `bench.php` is the standard A/B
harness against igbinary; the README's bench table is the regression
target. Always:

1. Build both the baseline (`master`) and the candidate with the same
   compiler flags (`-O2 -DNDEBUG` is the default for opt builds).
2. Run the bench at least 5x and report the median.
3. Show the diff in the PR description with absolute ns/op numbers
   and the percentage delta vs. the matching `master` cell.

Folklore optimizations (`computed-goto`, "skip the interned-string
branch", etc.) get rejected if the bench doesn't move. Branch
predictor / cache / inline-cache effects swamp the things that
*should* matter on paper.

## Release workflow

For maintainers cutting a new version:

1. Bump `PHP_PHPSER_VERSION` in `php_phpser.h` to the new semver and
   update the top section of `CHANGELOG.md`. The current
   `[Unreleased]` entries become the new version section with a
   release date and a compare link. Update the **Supported versions**
   table in `SECURITY.md` to the new minor (pre-1.0: latest minor
   only) — it does not track the version automatically.
2. Commit + push to master. CI (Tests workflow, all jobs green) is
   required before tagging.
3. `git tag -a X.Y.Z -m "phpser X.Y.Z"` with a release-note body,
   then `git push origin X.Y.Z`. Use bare semver (`0.1.1`, not
   `v0.1.1`) to match the fleet convention.
4. The `release-windows.yml` and `release-linux.yml` workflows pick
   up the tag, build the full matrix (PHP 8.2-8.5 x TS/NTS x x86/x64
   for Windows; PHP 8.4-8.5 x glibc/macOS for Linux), and attach the
   binaries to the release.
5. Packagist's GitHub webhook fires on tag push and re-scans
   versions. `pie install iliaal/phpser` resolves to the new tag
   within a minute or two.
6. Before the first tag of any release cycle, confirm `composer.json`
   exists at HEAD (`git ls-tree HEAD | grep composer.json`).
   Packagist silently skips tags whose commit doesn't contain
   `composer.json` at the root.

## License

By submitting a patch you agree to license your contribution under
the same license as the project (BSD-3-Clause).
