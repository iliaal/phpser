# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/iliaal/phpser/compare/0.1.1...HEAD
[0.1.1]: https://github.com/iliaal/phpser/releases/tag/0.1.1
[0.1.0]: https://github.com/iliaal/phpser/releases/tag/0.1.0
