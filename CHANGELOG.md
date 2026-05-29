# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/iliaal/phpser/compare/0.1.0...HEAD
[0.1.0]: https://github.com/iliaal/phpser/releases/tag/0.1.0
