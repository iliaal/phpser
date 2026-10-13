# Security policy

phpser is a binary serializer for PHP. It is **not** a sandbox for
untrusted input by default: like PHP's own `unserialize()`, decoding a
payload constructs PHP objects (calling `__unserialize` /
`__wakeup` / `Serializable::unserialize` along the way), so decoding
attacker-controlled bytes against an open class allowlist is equivalent
to letting the attacker run those magic methods.

**Use one of these patterns for untrusted storage:**

- `phpser_unserialize($payload, ['allowed_classes' => false])`
  rejects all classes (decodes them as `__PHP_Incomplete_Class`),
  matching PHP's native instantiation opt-out; positional object state has
  the documented divergence below.
- `phpser_unserialize($payload, ['allowed_classes' => [Foo::class, Bar::class]])`
  is an allowlist; classes outside the list decode as
  `__PHP_Incomplete_Class` with the original name preserved.
- `phpser_unserialize_signed($payload, $hmac_key)` verifies an
  HMAC-SHA256 framed payload and rejects tampered or foreign-keyed input before any decoding
  starts. Use for cache/session/cookie storage where the payload
  round-trips through a system you don't fully control (memcached,
  redis, signed cookies, etc.). Both the signing and verifying side throw
  on an empty `$hmac_key`, since a keyless HMAC is forgeable. Use a
  high-entropy secret, e.g. 32 random bytes.

A valid HMAC proves that the signer possessed the key. It does not prove the
body was emitted by `phpser_serialize_signed()`: code with the key can sign
handcrafted wire bytes. The signed decoder therefore applies the same
duplicate-key, numeric-key, integer-range, nesting, and collision-work checks
as the unsigned decoder before taking any structural fast path.

**The allowlist is a per-decode gate, not a sanitizer.** A value decoded
under `allowed_classes => false` (or a narrow allowlist) as
`__PHP_Incomplete_Class` still carries its original class name, and
re-encoding that incomplete object preserves it: decoding the re-encoded
bytes with a wider allowlist (or the default) resurrects the original
class with its state. Likewise, a value filtered on one read and cached
can come back to life on a later permissive read of the same bytes.
Never re-encode an `__PHP_Incomplete_Class` into a store that a
permissive reader trusts.

Wire-controlled HashTable keys use separate limits for full-hash collisions,
long bucket walks, and long string comparisons. The cumulative work allowance
is 65,536 plus 64 times the payload length; exhausting it rejects the frame.
Integer keys that merely share a bucket remain valid within that allowance.
These checks bound key insertion and schema validation, not all decoder work;
class resolution and application hooks have the limits described below.

Sub-linear wire is budgeted. `TAG_PACKED_AFFINE` reconstructs an integer run
from a constant number of wire bytes, so payload length alone no longer bounds
decoded memory on that tag. Both sides enforce the same cumulative 1,048,576
element budget (16 MiB of packed values on 64-bit PHP): the encoder falls back to
linear tags past it and the decoder rejects past it, so a legitimate encoder
cannot exceed that affine-element limit. The tag is also rejected as a
table column.

TABLE/ROWSET row allocations and copies of `__serialize` data into object
properties share a separate materialization allowance: 16 MiB plus 64 times
the payload length. Copies occur when a decoded class has no `__unserialize`
hook, including disallowed or unavailable classes. The charge estimates table
capacity and generated property keys using conservative 64-bit sizes; it is
not a bound on total process memory. The encoder falls back from TABLE when
its row-allocation excess would exhaust the fixed allowance. Empty associative
arrays reuse immutable storage instead of allocating a mixed table per tag.

Shared arrays make the decoded value a DAG. `TAG_SHARED_ARRAY` lets a later
`TAG_REF` reuse one decoded array by refcount, the way object and reference
back-references already reuse one instance. Reading an array back-reference
itself does not copy the table, but later property materialization can copy
its entries and is charged against the allowance above. The logical tree can
still be exponentially
larger than the payload: under 150 bytes describe an array whose recursive
expansion has a million leaves. `TAG_NEW_REF`/`TAG_REF` on PHP references and
shared objects (incomplete ones included) already allowed the same shape, so
phpser adds no budget for it; one would have to reject legitimate cache values
that repeat one lookup array thousands of times. Code that recursively walks
a decoded untrusted value without tracking identity (`json_encode()`,
`var_export()`, `serialize()`, `array_walk_recursive()`,
`count($v, COUNT_RECURSIVE)`, deep `==`) does work proportional to the
logical size, so bound that work at the application layer. The encoder shares
an array only when its traversal encounters no PHP reference. An array that
was initially ineligible can become shareable on a later visit when its
objects already have identities and encode as back-references; this prevents
repeated expansion while preserving reference aliases in those objects.
The decoder rejects a shared array whose contents create or reuse a PHP
reference during decoding. A back-reference to an existing object does not
traverse its properties, which may contain references. The decoder registers
a shared array only after its contents decode, so no back-reference can reach
an array that is still being built. Arrays containing direct PHP references
remain by-value; application work on their logical expansion still needs
its own limit.

Class resolution is *not* memoized on a miss, so a payload naming an unknown
class once per object triggers one autoloader invocation per object rather
than one per payload. This matches native `unserialize()`, which also calls
`zend_lookup_class` per object. Each lookup can scan and lowercase the class
name even when no autoloader is registered. A long dictionary name reused
by many object tags can therefore make class resolution quadratic in payload
size; the key-comparison budgets do not bound that work or application
autoloaders. Use `allowed_classes` to reject unknown names before resolution:
an explicit allowlist or `false` short-circuits those lookups.

Native decoding performed inside legacy SPL serialization handlers inherits
the outer class allowlist and the remaining nesting limit. An allowlisted
user-defined `Serializable::unserialize` method can still make its own
unrestricted decode calls; that user code is outside the allowlist gate.

**Signing key cache.** phpser caches HMAC key material per thread so a
signed call skips two SHA-256 compressions. The cache holds the most recent
signing key (raw bytes for keys up to 64 bytes, otherwise their SHA-256
digest) and the key-derived HMAC inner and outer states, in thread-local
memory. A different key overwrites the entry in place, and module shutdown
wipes it; a ZTS worker thread that exits before module shutdown frees its
copy without wiping it. The lookup compares the full 64-byte key block in
constant time, so timing reveals only whether the key matches the thread's
previous key.

**Session handler.** When built against the session extension, phpser
registers `session.serialize_handler = phpser`. Unless
`phpser.session_key` is set, this handler restores
`$_SESSION` through the **unsigned, all-classes-allowed** decode path,
with no `allowed_classes` filter and no HMAC, like the native
`php_serialize` handler. It therefore trusts the session store: anyone
who can write the session backend can instantiate arbitrary allowlisted
classes and trigger their `__wakeup` / `__unserialize` on the next read.
This is the standard PHP session trust model and is fine for a trusted
store (a private redis/memcached/file backend). If the session backend
is attacker-writable, set `phpser.session_key`.

**Signed sessions.** With `phpser.session_key` set, the handler appends an
HMAC-SHA256 tag to every session frame and verifies it, in constant time,
before any decode work. The tag key is derived per session:
`HMAC(session_key, "phpser:session:v1\0" || session_id)`. `php --ri phpser`
reports signing as `enabled`, `disabled`, or `invalid` (short key). Binding the ID
means a store writer cannot copy another user's validly signed blob into
their own session; the label keeps session tags distinct from
`phpser_serialize_signed()` tags made with the same key. A failed
verification raises an `E_WARNING` and returns failure to the session
engine, which destroys the session. Unsigned blobs, including sessions
written before the key was set, are rejected the same way. A key shorter
than 32 bytes fails closed: every session read, a new session's
included, is rejected with a warning and the session engine destroys
any existing session it read, rather than signing with a guessable key
(INI turns `On`, `yes`, and `true` into `"1"`) or falling back to
unsigned. A mistyped key therefore stops sessions working for everyone.
`Off`, `no`, `false`, `null`, and `none` become an empty value, which
disables signing. Quote the value in `php.ini`: an unquoted base64 key's `=` is a
syntax error that drops every later directive, including a
`session.serialize_handler` line that follows it.

Use a distinct key per application and per environment. The tag binds a
blob to the key and the session ID only, so a store writer could move a
blob signed by another application or environment that shares the key
(for example, a staging admin session) into this one under the same ID;
without `session.use_strict_mode`, the attacker can pick that ID.

The setting is `PHP_INI_SYSTEM | PHP_INI_PERDIR`, so application code
cannot replace it with `ini_set()`; it can still read it with
`ini_get()`, as it can read any other secret in its configuration. On
shared hosting, set it per pool (`php_admin_value`) so tenants do not
share or read each other's keys.

Protection holds only for requests that run with the key. A `.user.ini`
covers scripts at or below its directory; a script elsewhere that shares
the store decodes it unverified. `session.serialize_handler` is
`PHP_INI_ALL`, so any script that switches away from `phpser` also reads
the store unverified. Set both the handler and the key for every script
that shares the store, in `php.ini` or the pool configuration.

Limits:

- An empty store value still starts a new, empty session, so a store
  writer can log a user out.
- Rollback: a store writer can replay an older blob that was validly
  signed for the same session ID, restoring an earlier state of that
  session (for example, from before a role was revoked or a 2FA step
  completed). The tag binds the data to the ID, not to a time.
  `session_regenerate_id()` does not close this: a store writer who still
  holds the old ID, such as a demoted user who saved their own session
  file, can recreate the old record, and it verifies, including under
  `session.use_strict_mode`. Regeneration only stops a victim's session
  from being moved to an older state under the victim's new ID. Preventing
  rollback needs per-session state outside the store: keep authorization
  decisions (roles, revocations, completed 2FA) server-side and check them
  on each request, or keep a per-session version counter there.
- `session_encode()` output is bound to the current session ID, so
  `session_decode()` under a different ID fails.

## Supported versions

| Version | Supported          |
|---------|--------------------|
| 0.7.x   | :white_check_mark: |
| 0.6.x   | :x:                |
| 0.5.x   | :x:                |
| 0.4.x   | :x:                |

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
  `allowed_classes` restriction. This is the documented "trust the
  source" mode, equivalent to PHP's native `unserialize()` without
  the allowlist. Use the HMAC-signed entry point or pass
  `allowed_classes` for untrusted input.
- `__wakeup` / `__unserialize` / `Serializable::unserialize` side
  effects in user code when the class is allowlisted. Those are the
  application's responsibility; phpser only decides which classes
  get instantiated.
- Resource exhaustion from payloads larger than available memory.
  Cap input size at the application layer before calling decode.
- Attacks requiring write access to the PHP source, the extension
  binary, or the HMAC key material.

## Deliberate divergences from native `unserialize()`

phpser matches PHP's `unserialize($bytes, ['allowed_classes' => ...])`
semantics, with these intentional differences:

- **Enums are filtered by `allowed_classes`.** Native `unserialize()`
  does *not* consult `allowed_classes` on the enum (`E:`) path, so a
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
  0.4.0), so the signed path, the one you use for untrusted bytes,
  is unambiguous. The session handler reports decode failure to the
  engine (warning; a scalar-root payload fails the read rather than
  becoming an empty session). Prefer the signed entry point when you
  need to distinguish a decode failure from a legitimate `null`.

- **A shared array is captured at its first visit.** Native `serialize()`
  writes every occurrence of an array separately; phpser writes a
  pointer-shared nested array once and back-references the rest. The only
  observable difference is when a serialization hook mutates such an array
  in place between two occurrences, which only SPL storage writes on release
  builds can do (debug builds abort on `HT_ASSERT_RC1`): later occurrences
  then decode to the first-visit contents, as they already do for objects.

- **Unloaded, disallowed positional objects keep no slot state.**
  `TAG_OBJECT_SLOTS` carries values in class slot order without property names.
  Under `allowed_classes => false`, phpser will not autoload an
  attacker-selected class for the sole purpose of recovering that schema. If
  the class is not already resident, the result is an
  `__PHP_Incomplete_Class` with its original class marker but no decoded slot
  properties. Native serialization carries property names and can
  preserve that state. If the phpser class is already
  loaded, the known prefix is mapped without autoloading.
