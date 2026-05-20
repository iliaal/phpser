# phpser

A PHP serialization extension in C, targeting read-heavy cache workloads
where decode time matters more than encode time or payload size.

Status: V2. Round-trip clean on primitives, arrays (packed/assoc/sparse/
deleted), objects (stdClass + typed properties via `write_property`), and
cycles (depth-capped at 4096 — IS_REFERENCE is flattened, see Limitations
below). Pivoted from a Rust+rkyv prototype after the rkyv architecture
failed to beat `pecl/igbinary` in opt-mode benchmarks — see
`LEGACY-RKYV.md` for that journey and the data behind the pivot.

## Bench (opt PHP 8.4.22-dev NTS release, 2000 iters)

| Shape | Size: ig → ps | Encode: ig → ps | Decode: ig → ps |
|---|---|---|---|
| rowset_100 | 4570 → 5095 (+12%) | 8k → 11k ns (+30%) | 9k → 9.8k ns (+8%) |
| rowset_1000 | 47K → 49K (**+3%**) | 140k → 110k ns (**-22%**) | 95k → 99k ns (parity, +4%) |
| packed_1k | 5495 → **1941** (**-65%**) | 3.7k → **1.2k** ns (**-66%**) | 6.4k → **1.8k** ns (**-73%**) |
| packed_10k | 60K → **22K** (**-63%**) | 37k → **15k** ns (**-59%**) | 62k → **19k** ns (**-70%**) |
| deep_50 | 419 → 420 (parity) | 1.2k → **0.7k** ns (**-41%**) | 1.6k → **1.3k** ns (**-20%**) |

Wins: packed numerics ~65% smaller + ~70% faster decode + ~60% faster
encode. Deep-nested ~20–55% faster encode at parity size. **Rowset_1000
encode beats igbinary by ~22%**, decode at parity (+4%), size within 3%.

`rowset_100` encode (+30%) is the one durable gap — fixed-cost floor
for the dict header emission and first-row inline emissions, amortized
over too few rows to recover. The absolute time is small (10 µs for
the entire 100-row payload).

## What we kept from the experiment

The rkyv attempt produced one solid finding and several reusable design
ideas:

- **Pointer-equality dict intern.** Encoding hits a `*zend_string == *zend_string`
  check first; only on miss do we hash the bytes. Cuts intern cost to
  near-zero for rowset-shaped data where PHP literals share interned
  zend_strings.
- **Front-loaded string dictionary.** Same shape as igbinary's
  `compact_strings`, except we emit the table once at the head and
  reference by varint index from values. Trade-off: not streamable.
- **Refcount-reuse of zend_strings on decode.** Per-decode cache parallel
  to the dict — first reference allocates, subsequent ones `addref`.
- **HT_IS_PACKED detection via flag, not iteration.** Avoid scanning the
  buckets just to determine layout.
- **`arPacked` stride awareness.** PHP 8+'s packed-array layout stores
  zvals directly, not Buckets — stride is 16, not 32.
- **Sparse-packed fallback.** Arrays with holes (post-`unset`) preserve
  original int keys via Assoc rather than silently re-indexing.

## Where phpser diverges from igbinary

igbinary is the closest reference point. The areas where there's still
measurable perf to take, and that this project targets, are:

1. **Pre-sized HT + direct `arPacked` writes on decode.** When the wire
   format declares `PACKED_LEN N`, allocate the HT once via
   `zend_new_array(N)` and write directly into `arPacked` with `ZVAL_*`
   macros. Skips N `zend_hash_next_index_insert` calls, including their
   hash computation, growth checks, and capacity tuning. **Shipped.**
2. **Tagged scalar runs.** `[1, 2, 3, ...]` (1000 longs) emits as a
   single `PACKED_LONGS` header + N zigzag varints, not 1000 `(tag,
   varint)` pairs. Decode is one tight loop with no per-element tag
   dispatch. **Shipped.**
3. **Inline-cache pointer intern.** 16-slot ring of recently-seen
   `zend_string*`. Hit rate near 100% on rowset shapes (PHP interns
   literals; the same `"id"` zend_string pointer flows through every
   row). Skips the byte-hash entirely on cache hits. **Shipped.**
4. **Eager dict materialization with warm hashes.** All dict zend_strings
   allocated up front during header parse and their hashes pre-computed.
   `zend_hash_add_new` reuses the cached hash. **Shipped.**
5. **`add_new` insert path on assoc decode.** Skips the existence check
   since we know the encoder doesn't emit duplicate keys. **Shipped.**
6. **Inline-short-string tag — shipped via upgrade-on-second-encounter.**
   `TAG_STR_INLINE` (0x0c) and `KEY_STR_INLINE` (0x02) are emitted on a
   string's first occurrence; the next occurrence triggers an in-place
   upgrade to a dict entry, and all subsequent ones emit `TAG_STR_DICT`.
   Singletons (e.g. `row_X` values in a rowset) never hit the upgrade
   branch — they cost nothing in the dict header. The intern cache
   doubles as the "seen once?" signal: high bit of `idx` distinguishes
   `INLINE_EMITTED` from `DICT_IDX`. No pre-pass; single walk of the
   zval tree as before.

   This is approach B from the design notes. An earlier attempt at the
   two-pass version (approach A — count-then-emit) hit the
   ~200-ns-per-string cost of the count pre-pass, which exceeded the
   per-singleton savings; we left A out and shipped B. Effect:
   `rowset_1000` encode improved from -8% to -20% to -25% vs igbinary;
   payload size dropped from +5% to +2.7%.
7. **Skip refcount machinery during build.** All zvals built during decode
   are fresh and unshared until handed back to PHP — internal writes can
   skip `Z_TRY_ADDREF` guards.

## Build

### Standard PECL build (against any installed PHP)

```sh
phpize
./configure --enable-phpser
make -j$(nproc)
sudo make install
```

The `config.m4` auto-detects the session extension and registers phpser
as a `session.serialize_handler` when available.

### Local dev build (no install required)

The hand-rolled `Makefile` builds against the in-tree `~/php-src-8.4-opt`
without `phpize`/`autoconf`. Useful for hacking on the extension while
also hacking on PHP itself:

```sh
make -j$(nproc)           # builds modules/phpser.so
make test                 # runs tests/*.phpt via run-tests.php
```

Override `PHP_SRC=` to target a different in-tree PHP checkout.

Then load alongside igbinary for the A/B bench:

```sh
~/php-src-8.4-opt/sapi/cli/php \
  -d extension=$HOME/igbinary/modules/igbinary.so \
  -d extension=$(pwd)/modules/phpser.so \
  bench.php
```

## Limitations / known gaps

- **`IS_REFERENCE` is flattened.** PHP's `&$x` references don't survive
  round-trip — the value is encoded but the sharing isn't. For cache use
  this matches what most users expect (cached values are value-shaped),
  but it's a visible difference from `serialize()`/`unserialize()`. Full
  shared-ref support is a future addition (TAG_SHARED + TAG_REF pair).
- **Cyclic structures are capped at depth 4096.** Anything deeper turns
  into `NULL` at the cap point. Pure value-shaped data never hits this;
  it only fires when an `IS_REFERENCE` cycle would otherwise loop forever.
- **`__wakeup` / `__unserialize` / `Serializable` aren't called.** Object
  decode uses `object_init_ex` + `write_property` per slot. For classes
  that rely on `__wakeup` to restore invariants, the resulting instance
  is structurally correct but those hooks don't fire.
- **`session.serialize_handler=phpser` is shipped** (compiled in when
  `phpize` detects the session extension; gated on `HAVE_PHP_SESSION` so
  the extension still loads on session-less PHP builds). `phpredis`
  integration is not yet wired — call `phpser_serialize`/`unserialize`
  directly when using the extension as a phpredis serializer.

## Wire format (V1)

```
[u8 version=0x01]
[varint ndict]
  per entry: [varint len] [bytes]
[value]

value tags:
  0x00 NULL
  0x01 FALSE
  0x02 TRUE
  0x03 LONG          varint (zigzag-encoded)
  0x04 DOUBLE        8 bytes (LE)
  0x05 STR_DICT      varint dict_idx
  0x06 ASSOC         varint(len), N×(key, val)
  0x07 PACKED_MIXED  varint(len), N×val
  0x08 PACKED_LONGS  varint(len), N×zigzag-varint
  0x09 PACKED_DOUBLES varint(len), N×8-byte LE
  0x0a OBJECT        varint(class_idx), varint(nprops), N×(key_idx, val)

key:
  0x00 LONG          varint(zigzag)
  0x01 STR           varint(dict_idx)
```
