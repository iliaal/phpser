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

## V2 baseline numbers

PHP 8.4.22-dev **NTS DEBUG** build (debug build inflates both libraries'
absolute ns/op ~5–10× vs release; relative ratios should hold roughly).

| Shape | Size: ig → rk | Serialize: ig → rk | Unserialize: ig → rk |
|---|---|---|---|
| rowset_100 (100 rows × 7 fields) | 4.5K → 40K (+777%) | 16k ns → 510k ns (32×) | 100k ns → 146k ns (+46%) |
| rowset_1000 (1000 rows × 7 fields) | 47K → 399K (+741%) | 184k ns → 4.3M ns (23×) | 982k ns → 1.35M ns (+38%) |
| packed_1k (1000 ints) | 5.5K → 24K (+338%) | 7k ns → 230k ns (33×) | 34k ns → **26k ns (-24%)** |
| packed_10k (10000 ints) | 60K → 240K (+304%) | 47k ns → 2.0M ns (42×) | 345k ns → **256k ns (-26%)** |
| deep_50 (50 levels of nesting) | 420 → 4144 (+889%) | 4k ns → 65k ns (16×) | 23k ns → 22k ns (parity) |

**Where rkyvser wins**: packed numeric arrays — about 20–25% faster decode
than igbinary, validating the zero-copy architecture for this shape.

**Where rkyvser loses**:
- *Size*: 3–10× larger. rkyv pads everything for alignment to enable
  pointer-cast access. This is inherent to the format — varint encoding
  would fight rkyv's design.
- *Encode speed*: 16–42× slower. The `zval_to_value` walker materializes a
  full `PhpValue` tree before rkyv touches it. The "rkyv is memcpy" win
  is wiped out by the prebuild. Architectural fix is a manual `Archive`+
  `Serialize` impl on a `ZvalView` wrapper (V3 — see below).
- *Decode on rowsets*: 38–46% slower. Was 230% slower before V2's string
  refcount reuse landed. Closing the rest of the gap probably requires
  bypassing ext-php-rs's `ArrayKey::String` allocation during the encode
  side's HT iteration.

## V0 → V1 → V2 progression

| Version | Wire format | Decode model | Headline change |
|---|---|---|---|
| V0 (skeleton) | Inline `Str(Vec<u8>)` everywhere | Fresh `zend_string_init` per reference | Initial round-trip. |
| V1 | `PhpPayload { dict, root }` with `Str(u32)` indices | Fresh `zend_string_init` per reference | -40% payload size. Decode roughly unchanged. |
| V2 | Same as V1 | `StringCache<'a>` parallel to dict; first reference allocs, subsequent ones bump refcount | -60% rowset decode time vs V1. Now competitive with igbinary on reads. |

## What's left (roadmap)

1. **V3 — skip PhpValue materialization on encode.** Manual `Archive`/`Serialize`
   impl on a `ZvalView<'a>(&'a Zval, &'a mut Interner)` that emits rkyv bytes
   while walking the zval tree. Realistic target: 3–5× igbinary on encode
   (rkyv's per-slot alignment writes have inherent overhead vs varint).
2. **Object reconstruction.** Currently round-trips as `stdClass` with public
   props. Real impl needs `zend_lookup_class_ex`, property table init,
   `__wakeup` invocation, typed-property coercion. Read igbinary's
   `igbinary_unserialize_object_ser` before writing this.
3. **References / `IS_REF` / recursion.** Currently flattens. Add a per-encode
   id table, emit `Ref(u32)` variant. Detect cycles or refuse to encode them.
4. **zstd compression option.** rkyv's padding compresses extremely well —
   typically 5–10× on integer-heavy payloads. Opt-in 3rd arg or separate
   `rkyv_serialize_compressed()` pair.
5. **Non-debug PHP build for clean bench numbers.** Configure `~/php-src-8.4`
   without `--enable-debug` (or stand up a separate `~/php-src-8.4-opt`).
6. **phpredis serializer registration.** Hook `Redis::SERIALIZER_*`. Requires
   either a small phpredis patch or a wrapper class.
7. **`session.serialize_handler=rkyv`.** Needs `PS_SERIALIZER_ENCODE_FUNC` /
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
