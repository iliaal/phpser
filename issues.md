# Open issues

Last revalidated: 2026-05-20 against `HEAD` post-`ed9d2ac`. All items
below were validated with concrete repros (hand-crafted hostile payloads
and PHP-level test cases). Status reflects the post-fix commit.

## Resolved

### CR-001: typed-property and typed-reference invariants — RESOLVED

A hand-crafted TAG_OBJECT payload could plant a string into a declared
`int $x` slot, bypassing PHP's type machinery. Decoder now mirrors
`var_unserializer.re:608-708`:

- `zend_get_typed_property_info_for_slot()` on each declared-prop slot.
- `zend_verify_prop_assignable_by_ref(info, &tmp, /*strict*/ 1)` before
  installing.
- `ZEND_REF_DEL_TYPE_SOURCE` on slot overwrite if previously a typed ref.
- `ZEND_REF_ADD_TYPE_SOURCE` if the installed value is a reference, so
  writes through any alias are type-checked.

Cost: +2.7 ns per typed property decode (~ +8.9% on a 3-typed-prop class
× 500 objects). Unavoidable; matches PHP core's overhead.

### CR-003: dictionary index varint uint32 wrap — RESOLVED

`dec_get_zstr` now takes `uint64_t` and the call sites no longer narrow
before bound-check. Index `2^32` now correctly rejects instead of wrapping
to slot `0`. `key_val.dict_idx` also widened to `uint64_t`.

### CR-004: duplicate-key assoc HT corruption — RESOLVED

TAG_ASSOC decode switched from `zend_hash_*_add_new` (which silently
created malformed HTs with `count != actual entries`) to `zend_hash_*_update`
semantics. Duplicate keys collapse to the last value, matching PHP's
native `unserialize`.

### CR-005: session decode does not refresh `$_SESSION` — RESOLVED

`PS_SERIALIZER_DECODE_FUNC(phpser)` now mirrors PHP's own
`PS_SERIALIZER_DECODE_FUNC(php_serialize)` (session.c:986):

- After `ZVAL_NEW_REF(&PS(http_session_vars), &decoded)` and the addref,
  call `zend_hash_update_ind(&EG(symbol_table), "_SESSION", &PS(http_session_vars))`
  so userland `$_SESSION` rebinds to the new reference.

### CR-006: HMAC key material left on stack — RESOLVED

`phpser_hmac_sha256` now calls `ZEND_SECURE_ZERO(K, sizeof(K))` and
`ZEND_SECURE_ZERO(ctx, ops->context_size)` before returning. On glibc
this expands to `explicit_bzero`, which the compiler cannot optimize
away.

### CR-008: `allowed_classes` non-string entries silently skipped — RESOLVED

Non-string entry in the `allowed_classes` array now throws `TypeError`
with a message matching PHP's native shape:
`"phpser_unserialize(): allowed_classes option must be an array of class names, %s given"`.

### CR-009: doubles use host byte order — RESOLVED

Added `smart_str_append_le64` (encode) and `le64_read` (decode) helpers.
On LE hosts both compile to a single `memcpy` (the original code's cost).
On BE hosts the bytes are reversed so the wire format remains portable
per the README spec.

### CR-010: ZTS static TSRMLS cache setup incomplete — RESOLVED

Added `ZEND_TSRMLS_CACHE_DEFINE()` at the bottom of the TU and
`ZEND_TSRMLS_CACHE_UPDATE()` inside `PHP_MINIT_FUNCTION`, gated on
`COMPILE_DL_PHPSER && ZTS`. On NTS builds both macros expand to nothing.
The TLS slot is now properly wired for ZTS dynamic-extension loads.

### CR-011: bench.php references old rkyv extension — RESOLVED

`bench.php` updated: `rkyv_serialize/unserialize` → `phpser_*`; `$rk` →
`$ps`; stale Rust/rkyv build-flow comments removed.

### CR-012: README limitations are stale — RESOLVED

Rewrote the "Limitations / known gaps" section to reflect current
behavior (refs preserved, `__wakeup`/`__unserialize`/`Serializable` all
honored). Only remaining documented gaps: depth cap, closures+resources
as `NULL`, stdClass fallback for unknown classes, `phpredis` integration
not yet wired.

### Build warnings on `decode_value_inner` (`-Wmaybe-uninitialized`) — RESOLVED

`key_val k = {0}` initialization at the top of the TAG_ASSOC loop
silences both false positives. `decode_key` always sets `k.kind` plus
the matching union member, but gcc's flow analysis can't prove it.

## No outstanding issues at present.

Coverage for the regressions above lives in
`tests/076-issues-md-fixes.phpt` (15 cases). Full suite: 34/34 PHPTs pass.
Bench post-fix: only material regression is `decl_props_500 uns +8.9%`
(typed-prop check cost), all other shapes within noise.
