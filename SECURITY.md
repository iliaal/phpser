# Security policy

phpser is a binary serializer for PHP. It is **not** a sandbox for
untrusted input by default: like PHP's own `unserialize()`, decoding a
payload constructs PHP objects (calling `__unserialize` /
`__wakeup` / `Serializable::unserialize` along the way), so decoding
attacker-controlled bytes against an open class allowlist is equivalent
to letting the attacker run those magic methods.

**Use one of these patterns for untrusted storage:**

- `phpser_unserialize($payload, ['allowed_classes' => false])` —
  rejects all classes (decodes them as `__PHP_Incomplete_Class`),
  matching PHP's native instantiation opt-out; positional object state has
  the documented divergence below.
- `phpser_unserialize($payload, ['allowed_classes' => [Foo::class, Bar::class]])` —
  allowlist; classes outside the list decode as
  `__PHP_Incomplete_Class` with the original name preserved.
- `phpser_unserialize_signed($payload, $hmac_key)` — HMAC-SHA256 framed
  payload; rejects tampered or foreign-keyed input before any decoding
  starts. Use for cache/session/cookie storage where the payload
  round-trips through a system you don't fully control (memcached,
  redis, signed cookies, etc.). An empty `$hmac_key` is rejected with an
  exception on both the signing and verifying side — a keyless HMAC is
  forgeable, so callers must supply real key material (use a
  high-entropy secret, e.g. 32 random bytes).

A valid HMAC proves that the signer possessed the key. It does not prove the
body was emitted by `phpser_serialize_signed()`: code with the key can sign
handcrafted wire bytes. The signed decoder therefore applies the same
duplicate-key, numeric-key, integer-range, nesting, and collision-work checks
as the unsigned decoder before taking any structural fast path.

Wire-controlled HashTable keys are rejected when a collision chain exhausts
the decoder's bounded work budget. This prevents deterministic collisions in
Zend's stable string hash from turning a bounded payload into quadratic decode
CPU while leaving ordinary large arrays unrestricted by a global element cap.

**Session handler.** When built against the session extension, phpser
registers `session.serialize_handler = phpser`. This handler restores
`$_SESSION` through the **unsigned, all-classes-allowed** decode path —
it has no `allowed_classes` filter and no HMAC, exactly like the native
`php_serialize` handler. It therefore trusts the session store: anyone
who can write the session backend can instantiate arbitrary allowlisted
classes and trigger their `__wakeup` / `__unserialize` on the next read.
This is the standard PHP session trust model and is fine for a trusted
store (a private redis/memcached/file backend). If the session backend
is attacker-writable, do **not** rely on the open handler — sign the
payload at the application level with `phpser_serialize_signed` /
`phpser_unserialize_signed`, or gate reads through an explicit
`allowed_classes` allowlist. A future INI-configurable allowlist or
signed session frames would harden this path but are not shipped today.

## Supported versions

| Version | Supported          |
|---------|--------------------|
| 0.4.x   | :white_check_mark: |

Pre-1.0, security fixes land on the latest minor. Once 1.0 ships, the two
most recent minor versions will receive security fixes.

## Reporting a vulnerability

**Do not file a public GitHub issue for security vulnerabilities.**

Please use GitHub's private security advisory feature at
https://github.com/iliaal/phpser/security/advisories/new and include:

- A minimal reproduction: the PHP value being serialized (or the raw
  bytes if attacker-supplied), the options passed to
  `phpser_unserialize` / `phpser_unserialize_signed`, and the observed
  behavior (crash, OOM, unexpected object, etc.).
- The affected phpser version (`phpversion('phpser')`).
- The PHP version and OS (and ZTS/NTS).
- Your assessment of the impact (RCE via magic methods, memory
  corruption, DoS, HMAC bypass, etc.).

You can expect an initial acknowledgment within 72 hours. I'll work
with you on a fix and coordinate disclosure timing.

## Scope

In scope:

- Memory corruption (heap overflow, OOB read/write, UAF, double free)
  in the decoder against any wire input, including malformed or
  truncated payloads.
- HMAC bypass: any payload accepted by `phpser_unserialize_signed`
  without the correct key.
- `allowed_classes` bypass: a class outside the allowlist being
  instantiated as itself rather than `__PHP_Incomplete_Class`.
- Recursion-depth or quadratic-blowup attacks that bypass the 512
  nesting cap or otherwise cause unbounded CPU/memory use from a
  bounded-size input.
- Reference / cycle handling that produces dangling pointers or wrong
  refcount, leading to UAF.
- Side-channel HMAC verification timing leaks (`phpser_unserialize_signed`
  uses constant-time compare; report if you find a path that doesn't).

Out of scope:

- Calling `phpser_unserialize` on attacker-controlled bytes with no
  `allowed_classes` restriction — this is the documented "trust the
  source" mode, equivalent to PHP's native `unserialize()` without
  the allowlist. Use the HMAC-signed entry point or pass
  `allowed_classes` for untrusted input.
- `__wakeup` / `__unserialize` / `Serializable::unserialize` side
  effects in user code when the class is allowlisted. Those are the
  application's responsibility — phpser only decides which classes
  get instantiated.
- Resource exhaustion from payloads larger than available memory.
  Cap input size at the application layer before calling decode.
- Attacks requiring write access to the PHP source, the extension
  binary, or the HMAC key material.

## Deliberate divergences from native `unserialize()`

phpser matches PHP's `unserialize($bytes, ['allowed_classes' => ...])`
semantics, with these intentional differences:

- **Enums are filtered by `allowed_classes`.** Native `unserialize()`
  does *not* consult `allowed_classes` on the enum (`E:`) path — a
  serialized enum is always resurrected even under
  `allowed_classes => false`. phpser applies the filter to enums too:
  a disallowed enum decodes to `__PHP_Incomplete_Class`. Enum cases are
  inert singletons, so the security delta is small, but a decoder that
  advertises an allowlist should not instantiate an arbitrary enum
  outside it. If you rely on enums round-tripping through a filtered
  decode, include their class names in the allowlist.

- **Failure return value.** `phpser_unserialize()` returns `null` on a
  decode failure (malformed bytes, truncation, over-depth), which is
  indistinguishable from a successfully-decoded `null`. Native
  `unserialize()` returns `false` plus an `E_WARNING`.
  `phpser_unserialize_signed()` instead *throws* on failure (since
  0.4.0), so the signed path — the one you use for untrusted bytes —
  is unambiguous. Prefer it when you need to distinguish a decode
  failure from a legitimate `null`.

- **Unloaded, disallowed positional objects keep no slot state.**
  `TAG_OBJECT_SLOTS` carries values in class slot order without property names.
  Under `allowed_classes => false`, phpser will not autoload an
  attacker-selected class for the sole purpose of recovering that schema. If
  the class is not already resident, the result is an
  `__PHP_Incomplete_Class` with its original class marker but no decoded slot
  properties. Native serialization carries
  property names and can preserve that state. If the phpser class is already
  loaded, the known prefix is mapped without autoloading.
