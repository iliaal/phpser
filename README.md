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

phpser is decoder-optimized. Pointer-equality dict intern, refcount-reuse of zend_strings, pre-sized hash tables with direct `arPacked` writes, tagged scalar runs, an O(1) pointer-hash intern cache. On the shapes above, it cuts size by 60-65% and decode time by 70-77% vs igbinary. The wire-v2 columnar rowset format makes general-purpose rowsets 40-41% smaller than igbinary and 23-42% faster to encode.

phpser is also faster to **encode** than igbinary on every shape in the suite (-23% to -75%), so it's not just a read-path win. The one shape where igbinary stays even is rowset decode: `rowset_1000` decodes ~3% slower, the price the columnar format pays for a 41% smaller payload. The bench table below has the full shape-by-shape breakdown.

📖 **The design writeup:** [phpser: a fast, secure binary serializer for PHP cache workloads](https://ilia.ws/blog/phpser-a-fast-secure-binary-serializer-for-php-cache-workloads), on what the decoder does differently and why decode time is the metric to optimize. The [interactive benchmark page](https://iliaal.github.io/phpser/) compares phpser against igbinary, native `serialize()`, and msgpack across every cache shape.

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

Pre-built `.dll`s for Windows (PHP 8.2-8.5, TS/NTS, x64) and `.so`s for
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
$key = random_bytes(32);  // store this key in your app config; an empty key is rejected

$payload = phpser_serialize_signed($cacheValue, $key);
// ... later, possibly across a process boundary ...
$value = phpser_unserialize_signed($payload, $key);
// throws an Exception if the payload was tampered or signed with a different key
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

- **Signed payloads for integrity.** `phpser_serialize_signed($value, $key)` wraps the payload in an HMAC-SHA256 frame; `phpser_unserialize_signed($payload, $key)` verifies in constant time and rejects tampered or foreign-keyed input *before* any decoding work runs. Use this whenever the storage layer crosses a trust boundary: memcached, redis, files, cookies, anywhere an attacker who can write to the store could otherwise feed a crafted payload to your decoder. An empty key is rejected on both sides. A keyless HMAC is forgeable, so callers must supply real key material.
- **Safe handling of untrusted input.** `allowed_classes` option on both unserialize entry points, matching PHP's native `unserialize($payload, ['allowed_classes' => ...])` shape: pass `false` to reject all classes, an array to allowlist specific ones, or `true` for the default. Disallowed classes decode as `__PHP_Incomplete_Class` with the original name preserved, never instantiated. Recursion depth is capped at 512 on both encode and decode (encode throws, decode returns `null`), and assoc decode uses `zend_hash_update` so duplicate-key payloads collapse to last-write-wins rather than phantom buckets.
- **PHP 8.2+ (8.3, 8.4, 8.5, master).** BSD 3-Clause.

## Bench (PHP 8.4.22 aarch64, idle box, 1000 iters, median of 7)

| Shape | Size: ig → ps | Encode: ig → ps | Decode: ig → ps |
|---|---|---|---|
| rowset_100 | 4570 → **2727** (**-40%**) | 18.2k → **13.9k** ns (**-23%**) | 21.0k → 20.1k ns (-4%) |
| rowset_1000 | 47K → **28K** (**-41%**) | 258k → **151k** ns (**-42%**) | 213k → 219k ns (+3%) |
| packed_1k | 5495 → **1941** (**-65%**) | 9.8k → **2.5k** ns (**-75%**) | 15.8k → **3.4k** ns (**-79%**) |
| packed_10k | 60K → **22K** (**-63%**) | 94k → **25k** ns (**-74%**) | 154k → **36k** ns (**-77%**) |
| deep_50 | 419 → 424 (parity) | 2.8k → **1.7k** ns (**-37%**) | 3.5k → **2.9k** ns (**-16%**) |
| dto_100 | 7083 → **5506** (**-22%**) | 28k → **21k** ns (**-25%**) | 56k → **30k** ns (**-46%**) |
| dto_1000 | 73K → **57K** (**-23%**) | 315k → **221k** ns (**-30%**) | 617k → **310k** ns (**-50%**) |
| dto_mixed | 22K → **14K** (**-34%**) | 108k → **66k** ns (**-38%**) | 228k → **103k** ns (**-55%**) |

phpser encodes faster than igbinary on every shape in the suite (-23% to
-75%) and decodes faster on every shape except rowsets, where the two run
at parity. Packed numerics: ~64% smaller, ~74% faster encode, ~78% faster
decode. Deep-nested: ~37% faster encode at parity size.

Rowsets are the wire-v2 headline. The columnar `TAG_TABLE` format makes
them **40-41% smaller** than igbinary, where they used to run ~1% larger,
and **23-42% faster to encode**, at decode parity (-4% on 100 rows, +3% on
1000). The format trades a few percent of decode time on the large shape
for a 41% smaller payload, which is the whole reason to store a rowset
column-major.

DTO workloads (Laravel-queue-style payloads, single-class arrays) are now
**22-34% smaller, 46-55% faster to decode, 25-38% faster to encode** than
igbinary. Wire-v2 `TAG_OBJECT_SLOTS` drops the per-property key indices and
installs declared values straight into property slots; the dict dedups prop
names once, and the class-entry lookup cache amortizes `zend_lookup_class_ex`
across same-typed batches.

Sizes are byte-identical on x86 (the wire format is architecture-neutral);
the ns/op columns are from an idle aarch64 box, median of 7. For the full
four-way picture, phpser vs igbinary vs native `serialize()` vs msgpack,
with size, encode, and decode side by side on every shape, see the
**[interactive benchmark page](https://iliaal.github.io/phpser/)**.
Regenerate it with `php ... bench.php --html > docs/index.html`.

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
   miss instead of a linear scan, the change that put encode ahead of
   igbinary on every shape. Skips the byte-hash entirely on hits.
   **Shipped.**
4. **Eager dict materialization with warm hashes.** All dict slots are
   resolved up front during header parse, against the engine's
   interned-string table first. Property names, class names, and hot
   literals come back as the engine's own interned strings (no
   allocation, no refcount traffic, pointer-equality hash lookups),
   with a regular allocation as the fallback. Hashes are set on both
   paths; `zend_hash_add_new` reuses the cached hash. **Shipped.**
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
   version above is what ships. That step moved `rowset_1000` encode to
   25% faster than igbinary (up from 8% in the pre-upgrade implementation);
   the later columnar `TAG_TABLE` format took rowsets further still, to
   -41% size and -42% encode versus igbinary.
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
- **`TAG_OBJECT_SLOTS` is positional.** Eligible typed objects encode their
  declared properties as values in `properties_info_table` (declaration)
  order with no per-property names; decode installs them back in that order.
  A property *count* mismatch between encode and decode is rejected, but a
  same-count *reorder* of a class's typed properties is not detected, so the
  values land in the reordered slots silently. Same-deploy cache round-trips
  are safe (the class is identical on both sides). For signed payloads that
  outlive a deploy, evolve classes append-only (add properties at the end,
  don't reorder or retype existing ones) so older payloads keep decoding
  into the right slots.
- **`session.serialize_handler=phpser` is shipped** (compiled in when
  `phpize` detects the session extension; gated on `HAVE_PHP_SESSION` so
  the extension still loads on session-less PHP builds). `phpredis`
  integration is not yet wired; call `phpser_serialize`/`unserialize`
  directly when using the extension as a phpredis serializer.

## Wire format (V1 / V2)

```
[u8 version=0x01 or 0x02]
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
  0x12 OBJECT_SLOTS    varint(class_idx), varint(nprops), N×val  // wire v2 only;
                       declared-property values in properties_info_table order, no
                       per-prop key index. Emitted when every declared slot is
                       initialized; otherwise keyed OBJECT (0x0a) is used.
  0x13 ASSOC_DICT      varint(n), N×varint(dict_key_idx), N×val  // wire v2 only;
                       assoc with all string keys dict-bound; skips KEY_STR tags.
  0x14 ROWSET          varint(nrows), varint(ncols), N×varint(dict_key_idx),
                       nrows×ncols×val  // wire v2 only; packed homogeneous assoc
                       rows (rowset shape). Schema once, values row-major.
  0x15 TABLE           varint(nrows), varint(ncols), N×varint(dict_key_idx),
                       ncols×(col_tag, payload)  // wire v2 only; columnar rowset.
                       col_tag is PACKED_LONGS/DOUBLES/STRINGS/MIXED; nrows implicit.

key tags:
  0x00 LONG            varint(zigzag)
  0x01 STR             varint(dict_idx)
  0x02 STR_INLINE      varint(len), bytes
```

Varints are LEB128 (unsigned); signed values use zigzag encoding. Tags
0x10/0x11 plus 0x0a/0x0d/0x0e/0x0f each implicitly claim the next id in
encounter order, so the decoder reconstructs back-refs by counting
container tags as it parses.

## 🔗 Native PHP extensions

Companion native PHP extensions:

- **[php_excel](https://github.com/iliaal/php_excel)**: native Excel I/O via LibXL. 7-10× faster than PhpSpreadsheet, full XLS/XLSX with formulas, formatting, and styling.
- **[mdparser](https://github.com/iliaal/mdparser)**: native CommonMark + GFM markdown parser via md4c. 15-30× faster than pure-PHP libraries.
- **[php_clickhouse](https://github.com/iliaal/php_clickhouse)**: native ClickHouse client speaking the wire protocol directly. Picks up where SeasClick left off.
- **[pdo_duckdb](https://github.com/iliaal/pdo_duckdb)**: PDO driver for DuckDB, analytical SQL in your PHP stack.
- **[fastjson](https://github.com/iliaal/fastjson)**: drop-in faster `ext/json`, backed by yyjson. 6× encode, 2.7× decode, 5× validate.
- **[fast_uuid](https://github.com/iliaal/fast_uuid)**: high-throughput UUID generation (v1/v4/v7), batched CSPRNG and SIMD hex formatter, ramsey-compatible API.
- **[fastchart](https://github.com/iliaal/fastchart)**: native chart-rendering extension. 38 chart types behind one fluent OO API, SVG-canonical with PNG/JPG/WebP and optional PDF output.
- **[statgrab](https://github.com/iliaal/statgrab)**: system statistics (CPU, memory, disk, network) via libstatgrab, no parsing /proc by hand.
- **[phonetic](https://github.com/iliaal/phonetic)**: native phonetic name matching (Double Metaphone, Beider-Morse, Daitch-Mokotoff, NYSIIS, Match Rating), the encoders PHP core lacks.

---

[Follow on X](https://x.com/iliaa) • [Read the writeup](https://ilia.ws/blog/phpser-a-fast-secure-binary-serializer-for-php-cache-workloads) • If this cut your cache decode CPU, ⭐ star it!
