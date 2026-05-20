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
| rowset_100 | 4570 → 5103 (**+12%**) | 8k ns → 12k ns (+47%) | 9k ns → 10k ns (+14%) |
| rowset_1000 | 47K → 53K (**+11%**) | 141k → 132k ns (**-6%**) | 89k → 102k ns (+15%) |
| packed_1k | 5495 → **1941** (**-65%**) | 3.7k → **1.2k** ns (**-66%**) | 6.4k → **1.7k** ns (**-73%**) |
| packed_10k | 60K → **22K** (**-63%**) | 37k → **15k** ns (**-60%**) | 62k → **18k** ns (**-70%**) |
| deep_50 | 419 → 420 (parity) | 1.2k → **0.8k** ns (**-37%**) | 1.6k → **1.2k** ns (**-22%**) |

Wins: packed numerics ~65% smaller + ~70% faster decode + ~62% faster
encode. Deep-nested 22–37% faster on both sides at parity size.
Rowset_1000 encode now beats igbinary by a few percent. Rowset decode
within 10–20% of igbinary across all sizes.

The persistent gap on rowsets is +11% size (we don't yet inline short
strings or compactly encode tag+varint pairs the way igbinary does) and
~15% decode (zend_hash_add_new + key materialization per bucket is real
cost; closer parity needs bucket-direct writes via internal API).

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
6. **Inline-short-string tag (planned).** For string keys ≤7 bytes (common:
   `"id"`, `"name"`...), pack the bytes into the wire tag instead of
   going through the dict. Closes most of the remaining size gap on
   rowsets.
7. **Skip refcount machinery during build.** All zvals built during decode
   are fresh and unshared until handed back to PHP — internal writes can
   skip `Z_TRY_ADDREF` guards.

## Build

phpser uses the standard PHP extension build system. Targets in-tree
`~/php-src-8.4-opt` via a wrapper php-config:

```sh
phpize --with-php-config=$(pwd)/scripts/php-config-intree-opt
./configure --with-php-config=$(pwd)/scripts/php-config-intree-opt
make -j$(nproc)
```

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
- **No `phpredis`/`session.serialize_handler` integration yet.** You call
  `phpser_serialize`/`phpser_unserialize` directly.

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
