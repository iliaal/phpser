# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/iliaal/phpser/commits/master
