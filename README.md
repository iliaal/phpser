# phpser

[![Tests](https://github.com/iliaal/phpser/actions/workflows/tests.yml/badge.svg)](https://github.com/iliaal/phpser/actions/workflows/tests.yml)
[![License: BSD-3-Clause](https://img.shields.io/badge/License-BSD--3--Clause-green.svg)](https://opensource.org/licenses/BSD-3-Clause)

A PHP serialization extension in C, targeting read-heavy cache workloads
where decode time matters more than encode time or payload size.

Status: production-ready. Round-trip clean on primitives, arrays
(packed/assoc/sparse/deleted), objects (stdClass + typed properties),
references (`IS_REFERENCE` sharing preserved), object identity (back-refs
collapse to TAG_REF), cycles, enums, `__serialize`/`__unserialize`,
`__sleep`/`__wakeup`, and the legacy `Serializable` interface.
`allowed_classes` option and HMAC-SHA256 signed-payload mode are
supported.

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
sudo apt install -y git bison libtool-bin

# macOS
brew install bison libtool
```

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

## Bench (opt PHP 8.4.22-dev NTS release, 1000 iters, median of 9 runs)

| Shape | Size: ig → ps | Encode: ig → ps | Decode: ig → ps |
|---|---|---|---|
| rowset_100 | 4570 → **4771** (**+4.4%**) | 9k → 11k ns (+30%) | 9k → 10k ns (~parity) |
| rowset_1000 | 47K → 48K (**+1.1%**) | 143k → 113k ns (**-25%**) | 93k → 98k ns (+5%) |
| packed_1k | 5495 → **1941** (**-65%**) | 4.2k → **1.4k** ns (**-67%**) | 7.0k → **1.7k** ns (**-77%**) |
| packed_10k | 60K → **22K** (**-63%**) | 41k → **16k** ns (**-61%**) | 67k → **17k** ns (**-73%**) |
| deep_50 | 419 → 424 (parity) | 1.3k → **0.65k** ns (**-49%**) | 1.7k → **1.5k** ns (**-9%**) |
| dto_100 | 7083 → **6362** (**-10%**) | 14k → 18k ns (+22%) | 25k → **22k** ns (**-11%**) |
| dto_1000 | 73K → **65K** (**-12%**) | 175k → 173k ns (parity) | 250k → **214k** ns (**-14%**) |
| dto_mixed | 22K → 29K (+33%) | 54k → 76k ns (+42%) | 103k → **88k** ns (**-14%**) |

Wins: packed numerics ~65% smaller + ~75% faster decode + ~61% faster
encode. Deep-nested ~49% faster encode at parity size. **Rowset_1000
encode beats igbinary by ~25%**, size within 1.1%; decode pays a ~5%
tax for the front-loaded dict header walk + refcount-reuse machinery.
DTO workloads (Laravel-queue-style payloads, single-class arrays):
**10-12% smaller, 11-14% faster decode** vs igbinary thanks to dict
dedup on prop names + the class-entry lookup cache that amortizes
`zend_lookup_class_ex` across same-typed batches.

`rowset_100` encode (+30%) is the durable gap: a fixed-cost floor for
the dict header emission and first-row inline emissions, amortized
over too few rows to recover. The absolute time is small (11 µs for
the entire 100-row payload). Decode is essentially at parity (per-run
delta median +0.4%, absolute ratio +6%): the skip-DICT cache-eviction
policy keeps `['a','b','c']`-style repeated values in DICT slots so
`detect_packed_run` picks the `TAG_PACKED_STRINGS` typed-run path
instead of falling back to `PACKED_MIXED` mid-rowset.

`dto_mixed` encode (+42%) is the durable encode gap on object-heavy
shapes: `obj->handlers->get_properties` is called per object and
isn't trivially avoidable without a custom fast path for default
property layouts.

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
3. **Inline-cache pointer intern.** 16-slot ring of recently-seen
   `zend_string*`. Hit rate near 100% on rowset shapes (PHP interns
   literals; the same `"id"` zend_string pointer flows through every
   row). Skips the byte-hash entirely on cache hits. **Shipped.**
4. **Eager dict materialization with warm hashes.** All dict zend_strings
   allocated up front during header parse and their hashes pre-computed.
   `zend_hash_add_new` reuses the cached hash. **Shipped.**
5. **`update` insert on assoc decode.** Originally `add_new` to skip the
   existence-check, but adversarial wire payloads with duplicate keys
   would produce phantom buckets that violate PHP's last-write-wins
   semantic (`count($arr) != count(array_unique(array_keys($arr)))`).
   Reverted to `zend_hash_update` for security-boundary correctness;
   `add_new` is a real but small perf win the cost of breaking adversarial
   payloads cleanly. **Shipped.**
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

- **Recursion depth is capped at 512** on both encode and decode. Anything
  deeper than 512 nested containers / refs is rejected to bound stack
  consumption against adversarial wire payloads. Object cycles are
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
