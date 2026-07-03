# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Packed-array encode (numeric, double, and typed-string runs) now reserves
  the whole run's worst-case output capacity once, then writes elements raw,
  instead of running a `smart_str` capacity check per element. On small
  numeric arrays — where that per-element check was a large fraction of the
  total work — this cuts encode time ~26% (`packed_1k`); rowsets, whose
  tag-arrays travel the typed-string run, encode ~2% faster. The mixed-run
  path is unchanged (its recursion can reallocate the buffer mid-loop). Wire
  format and decode output are identical.
- The typed-string packed run now reads each element's dictionary index
  straight from the intern-cache slot rather than re-walking the intern
  path, which `detect_packed_run` has already shown is unnecessary (every
  element is proven dict-bound before the run is chosen). Rowset encode is
  a further ~5-6% faster on top of the reserve-once change. Decode and wire
  bytes unchanged.

### Fixed

- `allowed_classes` options carrying PHP references now behave like native
  `unserialize()`. Previously a reference-wrapped option value
  (`['allowed_classes' => &$flag]`) or an allowlist entry left referenced by
  a `foreach (... as &$c)` loop threw a spurious ValueError/TypeError instead
  of applying the filter. Both cases failed closed (the decode never ran), so
  this is a compatibility fix, not a security one.
- A crafted payload with a canonical numeric string array key (`"5"`) now
  decodes to the integer key `5`, matching native `unserialize()` and every
  PHP array write. The untrusted decode path previously preserved it as a
  string key, a HashTable state no PHP code can produce — letting an attacker
  smuggle a value past `isset()` / `array_key_exists()` checks that assume
  the key was already coerced. The HMAC-signed path was never affected (the
  encoder only ever emits integer keys for numeric strings).
- A `__serialize()` that returns a non-array without throwing now raises a
  `TypeError`, matching native PHP, instead of silently encoding the object
  as `null`. The previous behavior shipped a valid payload that decoded to
  `null` in the object's place with no error at write time.
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
  and accept forgeable payloads — defeating the signed path's only purpose.
  Both entry points now throw before any HMAC work.

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
  HashTable, the way native `serialize()` does — faster one-shot (fresh
  object) encode. PHP 8.4 lazy objects fall back to `get_properties()` so
  their initializer runs before serialization.
- `phpser_unserialize_signed()` decodes associative arrays with `add_new`
  instead of `update`: the HMAC proves the payload came from this
  extension's encoder (unique keys), so the per-key duplicate check is
  skipped. The unsigned path keeps last-write-wins collapse for untrusted
  input.

## [0.1.1] - 2026-06-01

### Changed

- `phpser_serialize()` and `phpser_serialize_signed()` now throw an
  exception when the value nests deeper than the recursion cap (512)
  instead of silently emitting a truncated payload that
  `phpser_unserialize()` could not decode (it returned `null`, losing all
  data). The session serialize handler degrades to an `E_WARNING` and skips
  the write rather than throwing during request shutdown.

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

[Unreleased]: https://github.com/iliaal/phpser/compare/0.3.0...HEAD
[0.3.0]: https://github.com/iliaal/phpser/releases/tag/0.3.0
[0.2.0]: https://github.com/iliaal/phpser/releases/tag/0.2.0
[0.1.2]: https://github.com/iliaal/phpser/releases/tag/0.1.2
[0.1.1]: https://github.com/iliaal/phpser/releases/tag/0.1.1
[0.1.0]: https://github.com/iliaal/phpser/releases/tag/0.1.0
