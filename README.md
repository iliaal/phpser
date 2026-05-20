# phpser

A PHP serialization extension in C, targeting read-heavy cache workloads
where decode time matters more than encode time or payload size.

Status: V1 in progress. Pivoted from a Rust+rkyv prototype after the rkyv
architecture failed to beat `pecl/igbinary` in opt-mode benchmarks — see
`LEGACY-RKYV.md` for that journey and the data behind the pivot.

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
   hash computation, growth checks, and capacity tuning.
2. **Tagged scalar runs.** `[1, 2, 3, ...]` (1000 longs) emits as a
   single `PACKED_LONGS` header + N zigzag varints, not 1000 `(tag,
   varint)` pairs. Decode is one tight loop with no per-element tag
   dispatch.
3. **Inline-string optimization (planned).** For string keys ≤7 bytes
   (common: `"id"`, `"name"`...), pack the bytes into the wire tag
   instead of going through the dict. Saves a dict entry and a pointer
   indirection.
4. **Skip refcount machinery during build.** All zvals built during decode
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

## Wire format (V1 draft)

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
