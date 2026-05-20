# rkyvser — Rust + rkyv experiment (archived)

This is the historical record of the V0→V3 attempt to build a PHP serializer
on top of rkyv via `ext-php-rs`. It did not deliver on its core pitch
(see status section below), so the project pivoted to a C extension —
`phpser`, in this same repo. See `README.md` for the live project.

The Rust source itself is preserved on the `legacy-rkyv` tag; current main
contains C only.

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

## Status: project pivoted to a C extension

The rkyv-backed approach **did not deliver on its core pitch in
production-realistic benchmarks**. See `LEGACY-RKYV.md` for the full V0→V3
journey, the design rationale, and the data. Short version:

PHP 8.4.22-dev **NTS RELEASE** build (the bench numbers below — these
are the real ones, not the inflated DEBUG numbers we initially measured):

| Shape | rkyvser ser vs igbinary | rkyvser uns vs igbinary | Size vs igbinary |
|---|---|---|---|
| rowset_100 | +330% (4.3× slower) | **+60% slower** | +777% larger |
| rowset_1000 | +275% (3.8× slower) | **+60% slower** | +741% larger |
| packed_1k | +370% (4.7× slower) | **+56% slower** | +338% larger |
| packed_10k | +330% (4.3× slower) | **+78% slower** | +304% larger |
| deep_50 | +172% (2.7× slower) | parity | +889% larger |

Earlier DEBUG-mode runs showed rkyvser 20–26% **faster** than igbinary on
packed numerics. That win was an artifact: debug-mode overhead inflated
igbinary's hot path proportionally more than ours. With both libraries
running optimized, igbinary wins decode on every shape — even packed
numerics, which was supposedly our home turf.

**Why the architecture didn't pan out:**

1. Parse cost wasn't the bottleneck. rkyv's "zero-copy parse" optimized
   the part of decode that isn't dominant.
2. Zval allocation is the actual hot path. ext-php-rs's `IntoZval`
   wrapper adds measurable indirection per entry that igbinary's
   direct `ZVAL_*` macro path avoids.
3. rkyv's structural overhead (alignment padding, per-node metadata,
   bytecheck validation) shows up clearly when underlying ops are fast.
4. The 3–10× size cost has no speed compensation anymore.

**What we kept from the experiment** (now informs the C extension):

- Front-loaded string dictionary with pointer-equality intern hits
- Refcount-reuse of zend_strings on decode (`StringCache` shape)
- Raw HT iteration via Bucket / arPacked pointer math
- `HT_IS_PACKED` flag check, not iteration scan
- Sparse-packed array fallback preserving original int keys
- Round-trip test corpus and bench.php skeleton

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
