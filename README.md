# phpser

[![Tests](https://github.com/iliaal/phpser/actions/workflows/tests.yml/badge.svg)](https://github.com/iliaal/phpser/actions/workflows/tests.yml)
[![Version](https://img.shields.io/github/v/release/iliaal/phpser)](https://github.com/iliaal/phpser/releases)
[![License: BSD-3-Clause](https://img.shields.io/badge/License-BSD--3--Clause-green.svg)](https://opensource.org/licenses/BSD-3-Clause)
[![Follow @iliaa](https://img.shields.io/badge/Follow-@iliaa-000000?style=flat&logo=x&logoColor=white)](https://x.com/intent/follow?screen_name=iliaa)

![phpser: high-performance PHP serializer, decoder-optimized for cache workloads](images/phpser-hero.jpg)

A PHP serialization extension in C, targeting read-heavy cache workloads
where decode time matters more than encode time or payload size.

## Why phpser?

PHP cache workloads pay decode cost on every read. Encode happens once per write. The default `igbinary` was the right answer for over a decade, but lags on three shapes that show up everywhere: packed numeric arrays, deep-nested structures, and same-class DTO batches (Laravel queue payloads, cached models).

phpser is decoder-optimized. Pointer-equality dict intern, refcount-reuse of zend_strings, pre-sized hash tables with direct `arPacked` writes, tagged scalar runs, an O(1) pointer-hash intern cache. On the shapes above, it cuts size by 60-65% and decode time by 70-77% vs igbinary. On general-purpose rowsets it sits within 1% of igbinary's size and encodes 18-53% faster.

phpser is now also faster to **encode** than igbinary on every shape in the suite (−14% to −70%), so it's no longer just a read-path win. The remaining non-wins are small and on the de-prioritized axes: rowset size runs ~1-4% over igbinary, and `rowset_1000` decode ~4% slower (the front-loaded dictionary trades streamability for decode speed everywhere else). The bench table below has the full shape-by-shape breakdown.

## Install

```bash
# PIE (PHP Foundation's extension installer; uses the composer.json
# at the repo root with type: "php-ext")
pie install iliaal/phpser
```

On a minimal PHP image (e.g. `php:8.x-cli` from Docker Hub), PIE needs a
few build tools installed first:

```bash
# Debian/Ubuntu
sudo apt install -y git bison libtool-bin unzip

# macOS
brew install bison libtool
```

`unzip` is load-bearing on Debian: composer shells out to `/usr/bin/unzip`
when extracting PIE's prebuilt-binary zip. If `unzip` is missing, composer
silently falls back to PHP's ZipArchive which lays the `.so` out at a
path PIE doesn't check, and install fails with `ExtensionBinaryNotFound`
even though the zip downloaded fine.

### From source

```bash
git clone https://github.com/iliaal/phpser.git
cd phpser
phpize && ./configure --enable-phpser
make -j$(nproc)
sudo make install
echo 'extension=phpser.so' | sudo tee /etc/php/conf.d/phpser.ini
```

### Pre-built binaries

Pre-built `.dll`s for Windows (PHP 8.3-8.5, TS/NTS, x64) and `.so`s for
Linux glibc (x86_64, arm64) and macOS arm64 (PHP 8.4-8.5) are attached
to each [GitHub release](https://github.com/iliaal/phpser/releases). PIE
fetches the matching binary automatically; falls back to source-build
when no asset matches.

## Usage

Basic round-trip. The encoded payload is opaque bytes; treat it as a
binary blob in storage (no JSON-safety, no UTF-8 guarantees):

```php
$payload = phpser_serialize(['id' => 42, 'name' => 'row', 'tags' => ['a','b']]);
$value   = phpser_unserialize($payload);
// $value === ['id' => 42, 'name' => 'row', 'tags' => ['a','b']]
```

HMAC-signed mode for untrusted storage (memcached, redis, files,
cookies). The signed entry points wrap the payload in a constant-time
HMAC-SHA256 frame; tampered or foreign-keyed input is rejected before
any decoding work runs:

```php
$key = random_bytes(32);  // store this key in your app config

$payload = phpser_serialize_signed($cacheValue, $key);
// ... later, possibly across a process boundary ...
$value = phpser_unserialize_signed($payload, $key);
// returns NULL if the payload was tampered or signed with a different key
```

`allowed_classes` option on both unserialize entry points. Same shape as
PHP's native `unserialize($payload, ['allowed_classes' => ...])`:

```php
// Reject all classes (decode them as __PHP_Incomplete_Class)
$value = phpser_unserialize($payload, ['allowed_classes' => false]);

// Allowlist specific classes; everything else becomes __PHP_Incomplete_Class
$value = phpser_unserialize($payload, ['allowed_classes' => [Foo::class, Bar::class]]);

// Allow all (default)
$value = phpser_unserialize($payload, ['allowed_classes' => true]);
$value = phpser_unserialize($payload);  // same as above
```

When decoding attacker-controlled bytes, use one of the two restricted
modes or the signed entry point. See `SECURITY.md` for the full threat
model.

## ✨ Features

- **Signed payloads for integrity.** `phpser_serialize_signed($value, $key)` wraps the payload in an HMAC-SHA256 frame; `phpser_unserialize_signed($payload, $key)` verifies in constant time and rejects tampered or foreign-keyed input *before* any decoding work runs. Use this whenever the storage layer crosses a trust boundary: memcached, redis, files, cookies, anywhere an attacker who can write to the store could otherwise feed a crafted payload to your decoder.
- **Safe handling of untrusted input.** `allowed_classes` option on both unserialize entry points, matching PHP's native `unserialize($payload, ['allowed_classes' => ...])` shape: pass `false` to reject all classes, an array to allowlist specific ones, or `true` for the default. Disallowed classes decode as `__PHP_Incomplete_Class` with the original name preserved, never instantiated. Recursion depth is capped at 512 on both encode and decode (encode throws, decode returns `null`), and assoc decode uses `zend_hash_update` so duplicate-key payloads collapse to last-write-wins rather than phantom buckets.
- **PHP 8.3+ (8.4, 8.5, master).** BSD 3-Clause.

## Bench (opt PHP 8.4.22-dev NTS release, 1000 iters, median of 9 runs)

| Shape | Size: ig → ps | Encode: ig → ps | Decode: ig → ps |
|---|---|---|---|
| rowset_100 | 4570 → **4771** (**+4.4%**) | 9.6k → **7.9k** ns (**-18%**) | 11k → 11k ns (~parity) |
| rowset_1000 | 47K → 48K (**+1.1%**) | 153k → **72k** ns (**-53%**) | 104k → 108k ns (+4%) |
| packed_1k | 5495 → **1941** (**-65%**) | 4.4k → **1.5k** ns (**-67%**) | 7.0k → **1.8k** ns (**-75%**) |
| packed_10k | 60K → **22K** (**-63%**) | 44k → **13k** ns (**-70%**) | 73k → **19k** ns (**-74%**) |
| deep_50 | 419 → 424 (parity) | 1.3k → **0.63k** ns (**-52%**) | 1.8k → **1.6k** ns (**-11%**) |
| dto_100 | 7083 → **6362** (**-10%**) | 16k → **13k** ns (**-14%**) | 27k → **23k** ns (**-15%**) |
| dto_1000 | 73K → **65K** (**-12%**) | 186k → **160k** ns (**-14%**) | 272k → **227k** ns (**-16%**) |
| dto_mixed | 22K → **18K** (**-17%**) | 59k → **40k** ns (**-32%**) | 111k → **79k** ns (**-29%**) |

phpser is faster to encode than igbinary on **every** shape in the
suite (−14% to −70%) while staying decoder-first. Packed numerics:
~65% smaller, ~70% faster encode, ~75% faster decode. Deep-nested:
~52% faster encode at parity size. **Rowsets encode 18-53% faster**,
size within ~1%; rowset decode pays a small (~4%) tax for the
front-loaded dict-header walk. DTO workloads (Laravel-queue-style
payloads, single-class arrays): **10-17% smaller, 15-29% faster
decode, 14-32% faster encode** vs igbinary — dict dedup on prop
names, the class-entry lookup cache that amortizes
`zend_lookup_class_ex` across same-typed batches, and an O(1)
pointer-hash intern cache that keeps the per-value dedup lookup off
the critical path.

The remaining non-wins are small and on the de-prioritized axes:
rowset size is ~1-4% over igbinary, and `rowset_1000` decode runs ~4%
slower — the front-loaded dictionary is read once at the head and
referenced by index, which is exactly what makes the other decodes
fast (not streamable; you can't have both).

Cross-validated on arm64 (aarch64, PHP 8.4.21 NTS, idle, median of 9):
same direction on every shape — encode −4% to −66%, decode wins on all
but `rowset_1000` (+4%). The encode margins on object shapes are
narrower than x86 (dto_100 −4%, dto_mixed −24%) but still ahead.

## Design highlights

The core ideas that drive the perf wins above:

- **Pointer-equality dict intern.** Encoding hits a `*zend_string == *zend_string`
  check first; only on miss do we hash the bytes. Cuts intern cost to
  near-zero for rowset-shaped data where PHP literals share interned
  zend_strings.
- **Front-loaded string dictionary.** Same shape as igbinary's
  `compact_strings`, except we emit the table once at the head and
  reference by varint index from values. Trade-off: not streamable.
- **Refcount-reuse of zend_strings on decode.** Per-decode cache parallel
  to the dict. First reference allocates, subsequent ones `addref`.
- **HT_IS_PACKED detection via flag, not iteration.** Avoid scanning the
  buckets just to determine layout.
- **`arPacked` stride awareness.** PHP 8+'s packed-array layout stores
  zvals directly, not Buckets. Stride is 16, not 32.
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
3. **O(1) pointer-hash intern.** Open-addressed `zend_string* → slot`
   hash, grown without eviction. Hit rate near 100% on rowset shapes (PHP
   interns literals; the same `"id"` zend_string pointer flows through
   every row), and unique value strings (names, emails) hit a single-probe
   miss instead of a linear scan — the change that put encode ahead of
   igbinary on every shape. Skips the byte-hash entirely on hits.
   **Shipped.**
4. **Eager dict materialization with warm hashes.** All dict zend_strings
   allocated up front during header parse and their hashes pre-computed.
   `zend_hash_add_new` reuses the cached hash. **Shipped.**
5. **Provenance-gated `add_new` on assoc decode.** The default
   (unsigned) path uses `zend_hash_update`: it's the security boundary, and
   adversarial payloads with duplicate keys must collapse to last-write-wins
   rather than produce phantom buckets (`count($arr) !=
   count(array_unique(array_keys($arr)))`). The HMAC-authenticated
   `phpser_unserialize_signed` path provably came from our own encoder
   (unique-keyed HashTables, no duplicates), so it uses `zend_hash_*_add_new`
   and skips the per-key existence check. **Shipped.**
6. **Inline-short-string tag with upgrade-on-second-encounter.**
   `TAG_STR_INLINE` (0x0c) and `KEY_STR_INLINE` (0x02) are emitted on a
   string's first occurrence; the next occurrence triggers an in-place
   upgrade to a dict entry, and all subsequent ones emit `TAG_STR_DICT`.
   Singletons (e.g. `row_X` values in a rowset) never hit the upgrade
   branch. They cost nothing in the dict header. The intern cache
   doubles as the "seen once?" signal: high bit of `idx` distinguishes
   `INLINE_EMITTED` from `DICT_IDX`. No pre-pass; single walk of the
   zval tree as before.

   A count-then-emit variant was tried first: pre-walk the zval tree
   to tag occurrences, then emit inline for singletons and dict for
   repeats. The pre-pass cost ~200 ns per string and ate the
   per-singleton savings, so the single-walk upgrade-on-second-encounter
   version above is what ships. `rowset_1000` encode landed at 25%
   faster than igbinary (up from 8% in the pre-upgrade implementation),
   with payload size dropping from +5% to +2.7%.
7. **Skip refcount machinery during build.** All zvals built during decode
   are fresh and unshared until handed back to PHP. Internal writes can
   skip `Z_TRY_ADDREF` guards.

## Local dev build

The hand-rolled `Makefile` builds against an in-tree `~/php-src-8.4-opt`
checkout without `phpize`/`autoconf`. Useful for hacking on the extension
while also hacking on PHP itself:

```sh
make -j$(nproc)           # builds modules/phpser.so
make test                 # runs tests/*.phpt via run-tests.php
```

Override `PHP_SRC=` to target a different in-tree PHP checkout. Load
alongside igbinary for the A/B bench:

```sh
~/php-src-8.4-opt/sapi/cli/php \
  -d extension=$HOME/igbinary/modules/igbinary.so \
  -d extension=$(pwd)/modules/phpser.so \
  bench.php
```

The `config.m4` auto-detects the session extension and registers phpser
as a `session.serialize_handler` when available.

## Limitations / known gaps

- **Recursion depth is capped at 512** on both encode and decode. On decode,
  anything deeper than 512 nested containers / refs is rejected (returns
  `null`) to bound stack consumption against adversarial wire payloads. On
  encode, input deeper than 512 throws an `Exception` rather than silently
  shipping a truncated payload. Object cycles are
  preserved correctly via the id-table machinery and don't count against
  this cap for shared-graph cases; the cap only fires on genuinely deep
  trees. Cache workloads typically nest 5-10 deep, so the cap is many
  orders of magnitude past any legitimate payload.
- **Closures and resources encode as `NULL`.** Same shape as PHP's own
  `serialize()`; these types are inherently non-serializable.
- **Unknown classes at decode fall back to `stdClass`** rather than PHP's
  `__PHP_Incomplete_Class`. This is deliberate for the typical cache
  workload; `allowed_classes => [...]` produces `__PHP_Incomplete_Class`
  with the original name preserved for disallowed classes, matching PHP.
- **`session.serialize_handler=phpser` is shipped** (compiled in when
  `phpize` detects the session extension; gated on `HAVE_PHP_SESSION` so
  the extension still loads on session-less PHP builds). `phpredis`
  integration is not yet wired; call `phpser_serialize`/`unserialize`
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
  0x03 LONG            varint (zigzag-encoded)
  0x04 DOUBLE          8 bytes (LE)
  0x05 STR_DICT        varint dict_idx
  0x06 ASSOC           varint(len), N×(key, val)
  0x07 PACKED_MIXED    varint(len), N×val
  0x08 PACKED_LONGS    varint(len), N×zigzag-varint
  0x09 PACKED_DOUBLES  varint(len), N×8-byte LE
  0x0a OBJECT          varint(class_idx), varint(nprops), N×(key_idx, val)
  0x0b PACKED_STRINGS  varint(len), N×varint(dict_idx)  // typed string run
  0x0c STR_INLINE      varint(len), bytes  // single-use string, skips dict
  0x0d ENUM            varint(class_idx), varint(case_name_idx)
  0x0e OBJECT_MAGIC    varint(class_idx), value  // class with __serialize;
                       // value is the array __serialize returned
  0x0f OBJECT_LEGACY   varint(class_idx), varint(len), bytes  // class with
                       // ce->serialize / ce->unserialize (Serializable etc.)
  0x10 REF             varint(id)  // back-ref to a previously-emitted container
  0x11 NEW_REF         value  // claims the next id for an IS_REFERENCE wrap

key tags:
  0x00 LONG            varint(zigzag)
  0x01 STR             varint(dict_idx)
  0x02 STR_INLINE      varint(len), bytes
```

Varints are LEB128 (unsigned); signed values use zigzag encoding. Tags
0x10/0x11 plus 0x0a/0x0d/0x0e/0x0f each implicitly claim the next id in
encounter order, so the decoder reconstructs back-refs by counting
container tags as it parses.

## 🔗 PHP Performance Toolkit

Companion native PHP extensions:

- [php_excel](https://github.com/iliaal/php_excel): native XLS/XLSX read/write via LibXL
- [mdparser](https://github.com/iliaal/mdparser): native CommonMark + GitHub Flavored Markdown parser
- [php_clickhouse](https://github.com/iliaal/php_clickhouse): native ClickHouse client over the binary protocol
- [fastchart](https://github.com/iliaal/fastchart): 19 chart types in one PHP extension
- [fastjson](https://github.com/iliaal/fastjson): drop-in faster `ext/json`, backed by yyjson
- [statgrab](https://github.com/iliaal/statgrab): system statistics wrapper around libstatgrab

---

[Follow on X](https://x.com/iliaa) • [Blog](https://ilia.ws) • If this cut your cache decode CPU, ⭐ star it!
