# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- Decoding a positional object of a class denied by `allowed_classes` no longer lowercases and hashes its whole class name again for each object. A 1 MB payload that repeated a long class name could take a minute of CPU (CWE-400; contributed by Nicolas Grekas).
- Encode no longer reads freed heap when a `__serialize` hook deletes a later row of a top-level `ArrayObject` or `ArrayIterator` storage array. The columnar path now pins top-level rows like nested ones (CWE-416; contributed by Nicolas Grekas).
- `phpser_serialize_signed()` and `phpser_unserialize_signed()` mark their `$key` parameter with `#[\SensitiveParameter]`, so exception traces no longer contain the signing key (CWE-532; contributed by Nicolas Grekas).

### Changed

- On PHP 8.3+, the extension refuses to start when the running PHP is an older patch release than the one it was compiled against, with a startup warning naming both versions, instead of loading and later ending the process with an undefined-symbol error. PHP reports `Unable to start phpser module` and exits; install a build for the running PHP version or build from source.
- Linux and macOS prebuilt binaries are built against PHP 8.4.27 and 8.5.12, so they include the memory_limit stop described under 0.10.0, and require PHP 8.4.27+ or 8.5.12+. On an earlier patch the extension refuses to start with the warning above; PIE still selects the prebuilt binary there, so build from source on those patches.

### Fixed

- Building phpser statically into a ZTS PHP no longer fails to link with a duplicate `_tsrm_ls_cache` symbol.

## [0.10.1] - 2026-10-26

### Fixed

- The 0.10.0 Linux and macOS prebuilt binaries failed on PHP 8.4 releases before 8.4.27 and 8.5 releases before 8.5.12 with an undefined-symbol error (`smart_str_ferealloc`), because they were compiled against the newest patch release; source builds were unaffected. Prebuilt binaries now compile against the oldest patch release of each PHP minor (8.4.1, 8.5.0), so they omit the memory_limit stop described under 0.10.0; build from source to get it on 8.4.27+ and 8.5.12+.

## [0.10.0] - 2026-10-26

### Changed

- **Compatibility change:** encoding `Closure` and other objects marked not serializable now throws an `Exception`, matching native `serialize()`, instead of silently encoding `NULL`. Nested occurrences abort the entire frame across plain, signed, keyed, C API, and session encoding. Resource handling is unchanged. Callers must handle the exception before writing cache values. Sessions are affected too: on PHP 8.5 and earlier, an explicit save (for example `session_write_close()`) of a session that holds such an object throws and stores an empty session, so the whole session is lost on the next request; PHP 8.6+ skips the write and keeps the previous data, and an automatic save at shutdown ends the request with an uncaught exception. Check `$_SESSION` for closures before upgrading.

### Fixed

- Fixed a use-after-free when a nested serialization callback resets a lazy proxy on PHP 8.4+ (reported by Henry Paradiz).
- On PHP 8.2.35, 8.3.36, 8.4.27, and 8.5.12, `phpser_serialize()`, `phpser_serialize_signed()`, and `phpser_serialize_keyed()` stop encoding, and `phpser_unserialize()`, `phpser_unserialize_signed()`, `phpser_unserialize_keyed()`, the session handler, and the APCu serializer stop decoding, once a memory_limit error is pending. The request ends with the ordinary memory_limit fatal and runs its shutdown functions, instead of allocating up to the hard limit and exiting the process. A payload whose declared element count or string length would size an allocation past memory_limit ends the same way, before that allocation is made, including a payload in APCu shared memory.

### Documentation

- Include keyed decoding in the class-allowlist, failure-signaling, and recursion-depth contracts throughout the README and security guide.
- Source installation instructions locate the active PHP configuration instead of assuming `/etc/php/conf.d`, and distinguish CLI from server SAPIs.
- On 32-bit builds, `phpser_unserialize_signed()` and `phpser_unserialize_keyed()` throw an `Exception` for out-of-range wire integers, where `phpser_unserialize()` returns `null`.
- String dictionary promotion and per-encode string cache memory are described as implemented.
- Session serializer support follows the target PHP's `HAVE_PHP_SESSION` for both `phpize` and `Makefile.dev` builds, and registration requires a startup load.

### For contributors

- A/B benchmarks average the two middle samples for even repetition counts, matching `bench.php`; both harnesses retain fractional median GC counters.
- `bench.php --html` names the reference column actually measured instead of always naming igbinary.
- `Makefile.dev` requires `PHP_SRC` to point at a configured php-src tree instead of defaulting to `~/php-src-8.4-opt`; run `make -f Makefile.dev clean` after changing `PHP_SRC`, `CC`, `CFLAGS`, or `LDFLAGS`.

## [0.9.0] - 2026-10-13

### Added

- `apc.serializer=phpser` APCu serializer, registered when phpser and APCu load at startup.
- `phpser.key` INI setting signs APCu values and keyed payloads with HMAC-SHA256 bound to a per-store context; keys under 32 bytes fail closed.
- `phpser_serialize_keyed()` and `phpser_unserialize_keyed()` sign with `phpser.key`.
- `php_phpser_api.h` C API (`phpser_api_serialize()`, `phpser_api_unserialize()`, `phpser_api_version()`) for other extensions; failed verification returns its own code.
- `phpinfo()` reports keyed signing status and APCu serializer registration, a startup `php` binding, and whether APCu has called phpser in the process.

### Changed

- On GCC and Clang, `phpser.so` exports only `get_module` and the C API.

### Fixed

- Loading phpser with `dl()` no longer registers its session serializer, which ext/session would keep calling after the module unloads.

## [0.8.0] - 2026-10-13

### Added

- `phpser.session_key` INI setting signs session frames with HMAC-SHA256 bound to the session ID; tampered, unsigned, and cross-session blobs are rejected before decoding, and keys under 32 bytes fail closed. `phpinfo()` reports signing as enabled, disabled, invalid, or inactive.

### Fixed

- Forked GC benchmark samples that exit without a result now mark only their serializer cell as unavailable instead of aborting the extended report.
- `ab.php` clamps `BENCH_ITERS` to at least 1.

## [0.7.1] - 2026-10-11

### Security

- Legacy SPL handlers inherit the decoder's class allowlist and nesting limit; native serialization contexts are restored after exceptions and fatal errors.
- Failed or skipped wakeup hooks suppress object destructors, and failed reference decoding leaves valid cleanup state. Nested reference wrappers are rejected before construction.
- Empty associative arrays reuse immutable storage. TABLE/ROWSET rows and fallback object-property copies share a bounded allocation allowance; large encoder inputs fall back to ordinary array tags.
- Long-key comparisons and bucket walks consume a cumulative work budget. Encoder dictionary collisions disable content deduplication while preserving pointer reuse and round-trips.
- Re-encoding shared arrays behind object references no longer expands their logical tree repeatedly; object identity and property-reference aliases are preserved.
- SECURITY.md distinguishes allocation and key-work limits from uncached class resolution, application hooks, and recursive walks of decoded graphs.

### Performance

- Plain objects (no `__sleep`, `__serialize`, or `__unserialize`) keep the positional `TAG_OBJECT_SLOTS` form after `get_object_vars()`, `foreach`, or `var_dump()` builds their property table.

### Changed

- Objects with a built property table follow the positional rules of untouched ones: a class missing at decode keeps only its name, a property inserted mid-class misplaces values (TypeError when types clash), and hooks that mutate later properties see the tail snapshot.

### Fixed

- Lazy ghosts and proxies with `__sleep` encoded their uninitialized properties; they now initialize first, as `serialize()` does.
- `__sleep` names that `serialize()` omits (parent-private, static, virtual, missing, repeated) are omitted with the same warnings.
- Autoloader exceptions abort decoding immediately; enum constant-evaluation failures propagate without dereferencing an invalid case.
- Legacy and magic object tags enforce class serialization restrictions. Unmangled declared properties resolve to their slots, and virtual properties cannot be shadowed by wire data.
- Numeric keys passed to `__unserialize` follow native array semantics. Arrays from internal classes with numeric string keys use ordinary array tags, and integer keys sharing hash buckets round-trip within the work budget.
- Decode pin cleanup retains its fallback in release builds.
- Resource compatibility guidance distinguishes native `int(0)` from phpser's `null`; neither restores the original resource handle.

### For contributors

- `Makefile.dev` supports in-tree PHP builds and tracks header dependencies without conflicting with phpize's generated Makefile.
- Benchmarks honor zero warmup iterations, clamp sample iterations to at least one, retry interrupted child waits, and keep failed samples local to their serializer cell.

## [0.7.0] - 2026-09-29

### Performance

- Decode adds one GC root per result instead of one per object; retained DTO batches with GC on decode 29-31% faster.
- Pointer-shared nested arrays encode once and decode as one refcounted table; a 1000-row rowset decodes 30% faster in 429 KB instead of 645 KB.
- Signed payloads use ARMv8 or SHA-NI SHA-256 and a per-thread key cache; `phpser_unserialize_signed()` is 53-85% faster on aarch64.
- Encoded payloads are allocated at exact size; a held 19-byte payload costs 48 bytes instead of 256.
- Wrapped payloads (`['data' => $rows, 'ttl' => 60]`) encode 5-9% faster from a better-sized intern cache.
- Scalars and small arrays decode 3-16% faster and shuffled integer arrays 15% faster from lower fixed decode costs.

### Changed

- New v2 wire tag `0x18 SHARED_ARRAY`; 0.6.x readers reject payloads that use it, 0.7.0 reads every 0.6.x payload.
- `phpinfo()` reports the HMAC SHA-256 backend and, for `ext/hash`, why it fell back.

### For contributors

- `bench.php --extended` covers single values, wrapped and signed payloads, GC-on retained decode, and Laravel-app shapes.
- Tests 132-157 cover decode GC roots, shared arrays, HMAC vectors, exact-size frames, and app-shaped round trips; CI adds a Linux arm64 lane.

## [0.6.2] - 2026-09-04

### Security

- Class-typed slots are now verified on decode. A payload smuggling a wrong-class
  object into a typed property is rejected instead of installed silently.
- Session reads of scalar or object payloads now fail instead of silently starting
  an empty session. The engine logs the failure and destroys the session.
- Signed and session payloads with trailing bytes are now rejected. Unsigned decode
  still tolerates them.

### Fixed

- Unknown classes now decode to `__PHP_Incomplete_Class` with the name preserved.
  Positional DTOs with an unavailable class decode to a property-less incomplete.
- Failed session encodes from coder limits persist a tombstone marker, so the next
  read fails loudly instead of resuming an empty session.
- `SECURITY.md` supported versions now track 0.6.x. The README documents the
  corrupt-input triple signal, the encode memory contract, and the id-claim rule.

### Changed

- `bench.php` now matches the published methodology: 35 timed reps, a warmup pass,
  GC held off during timing, median reported with IQR spread.

### For contributors

- Oracle rollup: 075 asserts warning-free null, 102 pins the pre-mutation snapshot,
  060/099 gain truncation/throw canaries, 097 pins v2 boundaries, 080 covers sessions.

## [0.6.1] - 2026-08-30

### Security

- Decode rejects `TAG_ASSOC_DICT`/`TAG_ROWSET`/`TAG_TABLE` frames with numeric-string or duplicate schema keys, which no real array produces; the old fallback routed them through an unbudgeted integer-key hash chain for quadratic decode from a bounded frame (CWE-400).
- Encode no longer reads freed heap when a `__serialize` hook mutates a live `ArrayObject`/`ArrayIterator` storage array it is walking; the generic walk copies a shared nested table and the columnar path pins each row before running hooks (CWE-416).

### For contributors

- `config.m4` and `config.w32` no longer define `HAVE_PHP_SESSION`; php-src's own session extension already provides it, and the header probe gating the duplicate never matched, so the session dependency was declared in a branch that never ran.

## [0.6.0] - 2026-08-04

### Performance

- Decode installs a typed property without the engine verify call when the value's type already matches the declared type exactly; same-class DTO batches decode ~13% faster (aarch64).
- `TAG_TABLE` decode builds row hashtables from a bucket template computed once per table instead of paying a hash insert per cell; rowsets decode ~15% faster (aarch64).
- Encode pre-sizes the string-intern cache from the top-level element count, skipping the per-doubling grow and rehash cascade; object and rowset encode 3-9% faster (aarch64) with identical wire bytes.
- Constant-stride integer arrays (ranges, constant fills) encode as a base-plus-step affine run: `range(0, 9999)` is a 7-byte payload that encodes 57% and decodes 66% faster (aarch64).
- Integer runs and rowset integer columns switch to delta encoding when the differences encode smaller than the values; rowset payloads shrink ~7% at a 2-4% rowset encode cost, decode unchanged.

### Changed

- New wire-v2 tags `TAG_PACKED_DELTA` (0x16) and `TAG_PACKED_AFFINE` (0x17). Payloads containing them decode to `null` on phpser 0.5.x and older, the same rollout contract as `TAG_TABLE`; payloads without integer runs remain byte-identical. Affine runs are capped by a one-million-element budget enforced identically at encode and decode.

### For contributors

- The Windows and Linux/macOS binary workflows accept `workflow_dispatch` and skip publication on it, so the release runners can be exercised on a candidate commit before the tag exists.

## [0.5.0] - 2026-07-27

### Security

- Fixed a use-after-free in signed (`phpser_unserialize_signed`) decode: a forged-but-validly-signed frame carrying a duplicate object-property, assoc, or rowset/table schema key could free an id-registered object on overwrite while a later back-reference still pointed at it. Objects are now pinned unconditionally for the decode pass (the signed fast path previously skipped the pin), and the trusted assoc/schema paths use uniqueness-gated inserts. A valid HMAC proves key possession, not honest-encoder provenance, so the decoder no longer trusts key uniqueness.
- Fixed a use-after-free in encode: a `__serialize`/`__sleep` hook that grows the array being walked through a by-reference alias reallocated the table under the element iterator. The array walk now holds a reference across user hooks so the write copy-on-write-separates instead, mirroring the existing object-property guard. (Native `serialize()` still exhibits this on the same shape.)
- Fixed a use-after-free when a nested serialization hook replaced a referenced `__sleep()` member name after phpser had borrowed its string pointer. The encoder now owns the selected property names until it finishes the object.
- Bounded decoder work on crafted Zend hash collisions. Wire-controlled keys can no longer turn associative arrays, object properties, or rowset schemas into quadratic CPU work.
- Rejected a dictionary count of `UINT32_MAX` before allocation. The previous `dict_len + 1` calculation wrapped to zero before the decoder populated the slots.

### Fixed

- Trusted (signed) `TAG_ASSOC_DICT` decode no longer builds a phantom duplicate-key bucket when a forged-but-signed payload carries a repeated key: the `add_new` fast path is gated on proven key uniqueness (and non-numeric keys) like the rowset/table paths, otherwise it falls back to last-write-wins. This also coerces a canonical integer-string key (e.g. `"5"`) to an int array key on the signed path, matching PHP array semantics.
- Trusted (signed) `TAG_ASSOC` and `TAG_ROWSET`/`TAG_TABLE` decode apply the same uniqueness/numeric-coercion gating: a forged-signed duplicate or canonical-numeric key can no longer produce a phantom bucket or a mistyped string key on the signed path.
- A non-array `__sleep()` return now emits the same `E_WARNING` as native `serialize()` before writing null in the object's place, instead of dropping the value silently.
- Encode aborts with the pending exception when a lazy object's initializer throws during serialization (and when a legacy `Serializable` C serializer returns success with an exception pending), instead of returning a truncated frame the session handler would persist.
- ZTS dynamically-loaded builds now refresh the thread-local storage cache in `RINIT`, not only `MINIT`, so worker threads under a threaded ZTS SAPI don't touch engine globals through an unpopulated cache. NTS builds register no `RINIT` and pay no per-request cost.
- Encode now discards a completed frame if releasing its temporary hook snapshots throws, so the userland and session entry points never return or persist bytes under a pending exception.
- Scalar `__sleep()` member names now raise PHP's native warning and coerce to strings instead of being dropped without a warning.
- A failed class lookup no longer poisons later lookups for the same wire class. An autoloader can make the class available on a later object in the graph. Autoloader cost therefore scales with object count rather than with distinct class names, matching native `unserialize()`; `SECURITY.md` documents the trade-off.
- Encoding an object no longer probes the class function table for `__sleep` before the back-reference check, so a repeated object reference emits `TAG_REF` without a hash lookup (35% faster encode on a back-reference-dominated payload, ~3% on a typical shared-object graph).
- On 32-bit PHP, decode now rejects wire integers outside the `zend_long` range instead of narrowing scalar values, packed runs, table columns, or array keys. Negative array keys are encoded from signed `zend_long` values so their canonical wire form still round-trips under the stricter decoder.

### Changed

- `TAG_TABLE` now deduplicates low-cardinality strings and equal packed-string vectors by content, making distinct-allocation rowsets 53% smaller and 44% faster to decode than igbinary on ARM.
- `TAG_TABLE` decode streams each column into the row arrays instead of materializing the columnar matrix first, dropping peak memory about 12% on large tables (60k x 4: 34.0 MB to 30.0 MB) with no speed change. Rows are materialized only after the first column decodes, so a rejected frame never pays for the rows it claims.
- `TAG_OBJECT_SLOTS` now accepts an older prefix of the current effective slot table; appended properties keep their class defaults and payloads with more slots than the current class still fail. Only append-at-end evolution is safe, since the decoder cannot tell an append from an insertion (README has the detail).

### For contributors

- `bench.php` rotates serializer order and rejects reference-contaminated distinct-allocation fixtures before timing.
- Malformed-input tests now require every curated invalid frame and every strict prefix of a valid non-null frame to decode as `null`; the legacy and magic-hook cases now reach the failure branches they name.
- `tests/123` pins the table reject path against allocating its claimed rows; `tests/124` pins the throwing-lazy-initializer abort on the session handler, which `tests/099` cannot observe because the userland path masks it behind the propagating exception.

## [0.4.0] - 2026-07-10

### Changed

- `phpser_unserialize_signed()` now throws instead of returning `null` when a payload verifies but its body fails to decode; a legitimately-signed `null` still decodes. (BC: callers must catch the exception for corrupt signed payloads.)
- `TAG_TABLE` encode gathers columns in one row-major pass instead of O(rows × cols²) per-column rescans; rowset encode ~20% faster (aarch64), no wire-format change.
- Rowset and table (`TAG_ROWSET`/`TAG_TABLE`) decode is ~24% faster (aarch64): dictionary strings resolve against the engine's interned-string table, and unique-schema rows skip the per-key duplicate check. No wire-format change.
- Removed the unreachable `TAG_ROWSET` encode path; `TAG_TABLE` already covers every string-keyed rowset. `TAG_ROWSET` decode is retained for older payloads.

### Fixed

- Fixed a crash decoding a truncated or corrupt `TAG_TABLE` column that dereferenced uninitialized memory during error cleanup (since 0.3.0).
- Rowset/table decode now coerces a canonical integer-string schema key (e.g. `"5"`) to an integer array key, matching PHP array semantics.
- `TAG_OBJECT_MAGIC` decode now fails the payload when a typed property rejects the value, instead of installing properties under a pending `TypeError`.
- A throwing `__serialize`/`__sleep`/`Serializable::serialize` no longer yields a truncated frame; encode aborts and the session handler declines to persist a partial `$_SESSION`.
- An over-4 GiB string or blob is skipped rather than copied into the encode buffer before rejection, avoiding a transient ~2× memory spike.
- The session-encode `E_WARNING` now names the actual cause (depth, the 4 GiB limit, or a throwing hook) instead of always reporting depth.
- `__sleep()` fixes its member set when it returns, matching native `serialize()`; a property created while an earlier member serializes is no longer included.

### Security

- `TAG_OBJECT_SLOTS` decode now routes values through `__unserialize` when the class defines it, like keyed `TAG_OBJECT`, instead of installing raw slots and running `__wakeup`; closes an invariant-rebuild bypass on the untrusted path.
- A `TAG_OBJECT_SLOTS` payload naming a disallowed class is no longer autoloaded; resolution is skipped unless the class is already resident, matching the other object tags.
- Decoding a denied class no longer lets a wire property named `__PHP_Incomplete_Class_Name` overwrite the placeholder's preserved name, which could resurrect an attacker-chosen class on a later decode (since 0.3.0).

## [0.3.0] - 2026-07-03

### Changed

- Wire format v2 (`0x02`) adds `TAG_TABLE` for homogeneous rowsets stored columnar: schema once, then per-column typed runs (`PACKED_LONGS`, `PACKED_DOUBLES`, `PACKED_STRINGS`, or `PACKED_MIXED`). Falls back to `TAG_ROWSET` when column typing does not apply.
- Wire format v2 (`0x02`) adds `TAG_ROWSET` for packed arrays of homogeneous assoc rows: column key schema once, then row-major values only. Falls back to `TAG_PACKED_MIXED` for single rows or mismatched schemas.
- Wire format v2 (`0x02`) adds `TAG_ASSOC_DICT` for assoc arrays whose keys are all dict-bound string refs, dropping the per-key `KEY_STR` tag byte. Row 2+ of a rowset qualifies once field names have upgraded into the dictionary.
- Wire format v2 (`0x02`) adds `TAG_OBJECT_SLOTS` for typed objects whose declared properties are all initialized: values emit in `properties_info_table` order with no per-property key index, and decode installs straight into slots without a `properties_info` hash lookup. Falls back to keyed `TAG_OBJECT` for `__unserialize` classes, uninitialized typed slots, and dynamic props. v1 (`0x01`) payloads still decode.
- Scalar values in assoc, packed-mixed, and object-property loops decode without the full recursive wrapper; HMAC-authenticated decode skips per-object id-table pins. Rowset and DTO decode ~6-8% faster (median of 51 runs, 2000 iters).

### Fixed

- Fixed a double-free decoding a trusted `TAG_TABLE` whose column key index is out of range.
- Fixed use-after-free encoding an object whose property `__serialize`/`__sleep` adds a dynamic property mid-walk; the property table is now ref-held during encode, as native `serialize()` does.
- Decode rejects a crafted `TAG_OBJECT` naming a legacy `Serializable` class with no `__unserialize`, matching native `unserialize` (raw property writes would bypass the class's `unserialize()`).
- Re-serializing a filtered `__PHP_Incomplete_Class` now recovers its original class name and drops the magic member, so it round-trips back to the real class once allowed, matching native `serialize()`.
- Encode throws on a string or blob over 4 GiB instead of emitting a payload the decoder's per-string length cap would reject as undecodable.

### Security

- Fixed a memory-exhaustion DoS where a small crafted `TAG_TABLE` payload forced a multi-gigabyte allocation on the untrusted decode path.

## [0.2.0] - 2026-06-11

### Added

- PHP 8.2 support (lowered the minimum from 8.3).

### Changed

- Decode resolves dictionary strings against the engine's interned-string
  table; interned hits skip the per-slot allocation and all refcount traffic.
  Rowset decode ~9%, DTO decode ~11-14% faster (arm64, median of 12).
- Object decode installs declared properties straight into property slots
  via `ce->properties_info` instead of materializing each object's
  properties HashTable; DTO decode a further ~22-25% faster, and decoded
  objects no longer carry the materialized table.
- Typed-string run encode fuses the eligibility scan with emission (one
  intern-cache probe per element instead of two); rowset encode ~6% faster,
  wire bytes unchanged.
- Object encode emits properties in a single pass with a back-patched
  count instead of a count walk plus an emit walk; DTO encode ~4% faster,
  wire bytes unchanged.
- Packed-array encode (numeric, double, and typed-string runs) reserves the
  run's worst-case output capacity once instead of a `smart_str` capacity
  check per element: `packed_1k` encode ~26% faster, rowsets ~2% faster.
  The mixed-run path is unchanged because its recursion can reallocate the
  buffer mid-loop. Wire format and decode output are identical.
- The typed-string packed run reads each element's dictionary index straight
  from the intern-cache slot, since `detect_packed_run` already proved every
  element dict-bound. Rowset encode a further ~5-6% faster; decode and wire
  bytes unchanged.

### Fixed

- `allowed_classes` options carrying PHP references now behave like native
  `unserialize()`. A reference-wrapped option value
  (`['allowed_classes' => &$flag]`) or an allowlist entry left referenced by
  a `foreach (... as &$c)` loop used to throw a spurious ValueError/TypeError
  instead of applying the filter. Both cases failed closed (the decode never
  ran), so this is a compatibility fix.
- A crafted payload with a canonical numeric string array key (`"5"`) now
  decodes to the integer key `5`, matching native `unserialize()` and every
  PHP array write. The untrusted decode path used to keep it as a string
  key, a HashTable state no PHP code can produce, which let an attacker
  smuggle a value past `isset()` / `array_key_exists()` checks that assume
  the key was already coerced. The HMAC-signed path was never affected (the
  encoder only emits integer keys for numeric strings).
- A `__serialize()` that returns a non-array without throwing now raises a
  `TypeError`, matching native PHP, instead of silently encoding the object
  as `null` with no error at write time.
- Decoding a payload whose deferred `__wakeup()` / `__unserialize()` throws
  no longer leaks the decoded object graph. The session decode handler now
  receives a cleared result on this error path, restoring the decoder's
  documented "result is null on failure" contract.

## [0.1.2] - 2026-06-02

### Security

- `phpser_serialize_signed()` and `phpser_unserialize_signed()` now reject an
  empty signing key instead of accepting it. An empty key reduces HMAC-SHA256
  to a fixed, keyless tag that anyone can recompute, so a misconfigured caller
  (e.g. `getenv('SECRET') ?: ''` with the variable unset) would silently emit
  and accept forgeable payloads. Both entry points now throw before any HMAC
  work.

### Changed

- Encode is now faster than igbinary across the whole benchmark suite
  (−14% to −70% depending on shape), where it previously lagged on small
  rowsets (+30%) and object-heavy payloads (+42%). The encoder's intern
  fast path is now an O(1) open-addressed pointer hash instead of a fixed
  linear ring, so unique value strings (names, emails, SKUs) no longer pay
  a linear-scan miss on every occurrence; varint emission reserves its
  worst-case byte count once rather than a capacity check per byte. The
  wire format and decode output are unchanged.
- `dto_mixed`-style payloads (objects interleaved with arrays) are now
  ~17% smaller than igbinary, where they were ~33% larger. The encode
  intern window was widened so repeated value strings (timestamps, status
  enums, currency codes) graduate into the front-loaded dictionary instead
  of re-emitting inline on every row.
- Plain objects with no dynamic-property table now serialize directly from
  their declared property slots instead of materializing a properties
  HashTable, as native `serialize()` does, for faster one-shot (fresh
  object) encode. PHP 8.4 lazy objects fall back to `get_properties()` so
  their initializer runs before serialization.
- `phpser_unserialize_signed()` began decoding associative arrays with
  `add_new` instead of `update`, based on the assumption that signed bodies
  came from this extension's encoder. The unsigned path kept
  last-write-wins collapse. The 0.5.0 security fixes remove that
  provenance assumption and validate key invariants on signed frames too.

## [0.1.1] - 2026-06-01

### Changed

- `phpser_serialize()` and `phpser_serialize_signed()` now throw an
  exception when the value nests deeper than the recursion cap (512)
  instead of silently emitting a truncated payload that
  `phpser_unserialize()` could not decode (it returned `null`, losing all
  data). The session serialize handler emits an `E_WARNING` and returns
  failure instead of handing PHP a partial frame; PHP's session core may
  replace the previous stored payload with empty bytes on that failure.

### Fixed

- Session serialize handler: a brand-new (empty) session is no longer
  rejected. The engine reads back an empty string for a fresh session and
  phpser's decoder treated it as malformed, so every first request emitted
  "Failed to decode session object" and destroyed the session. Empty input
  now decodes as an empty session, matching PHP's native serializers.
- The `allowed_classes` `TypeError` raised by
  `phpser_unserialize_signed()` now names that function rather than
  `phpser_unserialize()`.
- `__unserialize()` and `__wakeup()` are now selected from the class
  definition rather than the on-wire object form, matching native
  `unserialize()`. A class that defines `__unserialize()` but not
  `__serialize()` is now rebuilt through `__unserialize()` instead of by
  raw property writes, and a class with `__serialize()` plus `__wakeup()`
  but no `__unserialize()` now has `__wakeup()` called.

### Security

- Decoding a crafted payload that named a missing enum case, or a class
  constant that is not a case, no longer crashes. The case was validated
  through `zend_enum_get_case()`, whose internal assertion compiles out in
  release builds, so a missing case dereferenced a null pointer and a
  non-case constant was misread as an object pointer. Both are now
  rejected to `null` before any object is built.
- Decoding a crafted object payload that names a non-serializable class
  (`Closure`, `Generator`, `Fiber`) no longer yields a corrupt instance
  that crashes on first access, and naming an interface, trait, or
  abstract class no longer leaves a thrown exception pending past the
  decoder's null-return contract. Both are rejected to `null`, matching
  the classes PHP's native `unserialize()` refuses. Selecting the rebuild
  path from the class (above) also stops a crafted plain-object tag from
  skipping a class's `__unserialize()`/`__wakeup()` invariant rebuild.

## [0.1.0] - 2026-05-20

### Added

- Initial release of the phpser binary serializer.
- `phpser_serialize($value)` / `phpser_unserialize($payload, $options)` for
  framed binary serialization with a front-loaded string dictionary.
- `phpser_serialize_signed($value, $key)` /
  `phpser_unserialize_signed($payload, $key, $options)` for HMAC-SHA256
  tamper detection over untrusted storage (memcached, redis, files).
- `allowed_classes` option on both unserialize entry points, matching
  PHP's native `unserialize()` second-arg behavior (`true` allows all,
  `false` blocks all to `__PHP_Incomplete_Class`, array allowlist).
- `session.serialize_handler = phpser` registration (gated on the
  session extension being available at build time).
- Round-trip coverage: primitives, arrays (packed / assoc / sparse /
  deleted), objects (stdClass + typed properties), references
  (`IS_REFERENCE` sharing preserved), object identity (back-refs
  collapse to `TAG_REF`), cycles, enums, `__serialize`/`__unserialize`,
  `__sleep`/`__wakeup`, and the legacy `Serializable` interface.

[Unreleased]: https://github.com/iliaal/phpser/compare/0.10.1...HEAD
[0.10.1]: https://github.com/iliaal/phpser/releases/tag/0.10.1
[0.10.0]: https://github.com/iliaal/phpser/releases/tag/0.10.0
[0.9.0]: https://github.com/iliaal/phpser/releases/tag/0.9.0
[0.8.0]: https://github.com/iliaal/phpser/releases/tag/0.8.0
[0.7.1]: https://github.com/iliaal/phpser/releases/tag/0.7.1
[0.7.0]: https://github.com/iliaal/phpser/releases/tag/0.7.0
[0.6.2]: https://github.com/iliaal/phpser/releases/tag/0.6.2
[0.6.1]: https://github.com/iliaal/phpser/releases/tag/0.6.1
[0.6.0]: https://github.com/iliaal/phpser/releases/tag/0.6.0
[0.5.0]: https://github.com/iliaal/phpser/releases/tag/0.5.0
[0.4.0]: https://github.com/iliaal/phpser/releases/tag/0.4.0
[0.3.0]: https://github.com/iliaal/phpser/releases/tag/0.3.0
[0.2.0]: https://github.com/iliaal/phpser/releases/tag/0.2.0
[0.1.2]: https://github.com/iliaal/phpser/releases/tag/0.1.2
[0.1.1]: https://github.com/iliaal/phpser/releases/tag/0.1.1
[0.1.0]: https://github.com/iliaal/phpser/releases/tag/0.1.0
