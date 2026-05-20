# rkyvser

A PHP serialization extension built on [rkyv](https://rkyv.org/), targeting
read-heavy cache workloads where decode time matters more than encode time
or payload size.

Status: working V2. Round-trip is clean on all primitive + array shapes.
Object reconstruction is still a stub (round-trips through `stdClass` shape).

## What's here

- `src/lib.rs` — the extension. Wire format is `PhpPayload { dict, root }`
  with a front-loaded string dictionary. Decode caches `*mut zend_string`
  per dict slot and reuses via refcount bumps, the same shape as igbinary's
  `compact_strings`.
- `bench.php` — A/B vs `pecl/igbinary` on rowset / packed / nested shapes.
- `scripts/php-config-intree` — wrapper php-config that points at the in-tree
  `~/php-src-8.4` headers, so we don't need `make install`.

## Build

```sh
PHP_CONFIG=$(pwd)/scripts/php-config-intree \
PHP=$HOME/php-src-8.4/sapi/cli/php \
cargo build --release
```

Then load both `librkyvser.so` and `igbinary.so` into the local PHP:

```sh
~/php-src-8.4/sapi/cli/php \
  -d extension=$HOME/igbinary/modules/igbinary.so \
  -d extension=$HOME/ai/phprkyv/target/release/librkyvser.so \
  bench.php
```

## V3 baseline numbers

PHP 8.4.22-dev **NTS DEBUG** build (debug build inflates both libraries'
absolute ns/op ~5–10× vs release; relative ratios should hold roughly).

| Shape | Size: ig → rk | Serialize: ig → rk | Unserialize: ig → rk |
|---|---|---|---|
| rowset_100 | 4.5K → 40K (+777%) | 17k ns → 257k ns (15×) | 103k ns → 142k ns (+38%) |
| rowset_1000 | 47K → 399K (+741%) | 177k ns → 1.5M ns (8.5×) | 1001k ns → 1370k ns (+37%) |
| packed_1k | 5.5K → 24K (+338%) | 7k ns → 65k ns (9.3×) | 36k ns → **27k ns (-26%)** |
| packed_10k | 60K → 240K (+304%) | 63k ns → 581k ns (9.2×) | 334k ns → **266k ns (-20%)** |
| deep_50 | 420 → 4144 (+889%) | 4k ns → 35k ns (8.6×) | 21k ns → 20k ns (parity) |

**Where rkyvser wins**: packed numeric arrays — about 20–26% faster decode
than igbinary, validating the zero-copy architecture for this shape.

**Where rkyvser loses**:
- *Size*: 3–10× larger. rkyv pads everything for alignment to enable
  pointer-cast access. This is inherent to the format — varint encoding
  would fight rkyv's design.
- *Encode speed*: 8.5–15× slower (was 16–42× in V2). V3's raw zend FFI on
  HT iteration cut this in half. Remaining gap is rkyv's per-slot
  alignment writes in `to_bytes` + the `PhpValue` tree allocation itself.
  Closing further requires either a manual `Archive`/`Serialize` impl on
  a `ZvalView` wrapper, or accepting that rkyv-class formats won't match
  varint streamers on encode.
- *Decode on rowsets*: 37–38% slower. Was 230% slower before V2's string
  refcount reuse. Closing the remainder probably requires custom bucket
  insertion that skips ext-php-rs's `into_zval` overhead per entry.

## V0 → V1 → V2 → V3 progression

| Version | Wire format | Encode model | Decode model | Headline change |
|---|---|---|---|---|
| V0 (skeleton) | Inline `Str(Vec<u8>)` | `zval_to_value` via `ext-php-rs` HT iter | Fresh `zend_string_init` per ref | Initial round-trip. |
| V1 | `PhpPayload { dict, root }` with `Str(u32)` | Same | Same | -40% payload size. |
| V2 | Same as V1 | Same | `StringCache<'a>` with refcount reuse | -60% rowset decode vs V1. Competitive with igbinary on reads. |
| V3 | Same as V2 | Raw zend FFI: Bucket pointer math, `HT_IS_PACKED` via flag, pointer-keyed dict | Same as V2 | -50 to -73% encode time. Was 24× slower than igbinary on rowsets, now 8.5×. |

## What's left (roadmap)

1. **Encode-side: skip PhpValue materialization entirely.** Manual
   `Archive`/`Serialize` impl on a `ZvalView<'a>(&'a Zval, &'a mut Interner)`
   that emits rkyv bytes while walking. Realistic target: 3–5× igbinary on
   encode (rkyv's per-slot alignment writes have inherent overhead vs
   varint). Largest remaining lever on encode.
2. **Object reconstruction.** Currently round-trips as `stdClass` with public
   props via an assoc HashTable — `serialize($obj) !== serialize($rt)` is
   the visible failure mode. Real impl needs `zend_lookup_class_ex`,
   property table init, `__wakeup` invocation, typed-property coercion.
   Read igbinary's `igbinary_unserialize_object_ser` before writing this.
3. **References / `IS_REF` / recursion.** Currently flattens. Add a per-encode
   id table, emit `Ref(u32)` variant. Detect cycles or refuse to encode them.
4. **Custom HT insert that skips `into_zval`.** Decode side currently goes
   through ext-php-rs's `ht.push` / `ht.insert_at_index`, which each call
   `IntoZval::into_zval(zval, false)` even though our value is already a
   zval. Direct `zend_hash_index_update(ht, idx, &mut zv)` would shave
   per-entry overhead — visible on rowsets.
5. **zstd compression option.** rkyv's padding compresses extremely well —
   typically 5–10× on integer-heavy payloads. Opt-in 3rd arg or separate
   `rkyv_serialize_compressed()` pair.
6. **Non-debug PHP build for clean bench numbers.** Configure `~/php-src-8.4`
   without `--enable-debug` (or stand up a separate `~/php-src-8.4-opt`).
7. **phpredis serializer registration.** Hook `Redis::SERIALIZER_*`. Requires
   either a small phpredis patch or a wrapper class.
8. **`session.serialize_handler=rkyv`.** Needs `PS_SERIALIZER_ENCODE_FUNC` /
   `PS_SERIALIZER_DECODE_FUNC` registration in MINIT.

## Sharp edges already known

- `ext-php-rs` 0.13 → 0.15 had real API drift (`ArrayKey::Str` was added,
  `get_properties` switched from `Option` to `Result`, `insert_at_index` key
  type changed to `i64`). Pin to 0.15 for now.
- `zend_hash_update` (the `*zend_string`-keyed variant) isn't in ext-php-rs's
  allowed_bindings. We declare it ourselves; signature is stable PHP API.
- `rkyv::access` requires `bytecheck` feature; without it you get unsafe
  `access_unchecked` only. Don't ship without validation on untrusted reads.
- The local php-src `scripts/php-config` and `scripts/phpize` ship without
  the executable bit set, and emit paths into `/usr/local/include/php`
  (which only exist after `make install`). `scripts/php-config-intree`
  works around the former; igbinary was rebuilt with that wrapper.
