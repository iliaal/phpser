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
  matches PHP's native opt-out.
- `phpser_unserialize($payload, ['allowed_classes' => [Foo::class, Bar::class]])` —
  allowlist; classes outside the list decode as
  `__PHP_Incomplete_Class` with the original name preserved.
- `phpser_unserialize_signed($payload, $hmac_key)` — HMAC-SHA256 framed
  payload; rejects tampered or foreign-keyed input before any decoding
  starts. Use for cache/session/cookie storage where the payload
  round-trips through a system you don't fully control (memcached,
  redis, signed cookies, etc.).

## Supported versions

| Version | Supported          |
|---------|--------------------|
| 0.1.x   | :white_check_mark: |

Once 1.0 ships, the two most recent minor versions will receive security
fixes.

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
