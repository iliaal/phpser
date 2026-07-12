--TEST--
phpser: signed (trusted) forged rowset/table with duplicate schema keys — no phantom buckets, no back-ref UAF
--EXTENSIONS--
phpser
--FILE--
<?php
// =====================================================================
// dec_read_schema_keys() gated add_new on `d->trusted || unique`, so a
// forged-but-signed ROWSET/TABLE with duplicate schema keys skipped the
// uniqueness scan entirely -> per-row phantom buckets, and (with the
// numeric-forced update path over an unpinned object cell) the CR-001 UAF
// class (CR-004). Fix: evaluate uniqueness on every path, and objects are
// pinned at registration so within-row overwrite is memory-safe.
// =====================================================================
function v($n){ $o=""; while($n>=0x80){ $o.=chr(($n&0x7f)|0x80); $n>>=7;} return $o.chr($n); }
$key = str_repeat("k", 32);
function sign($body,$key){ return $body . hash_hmac('sha256',$body,$key,true); }

// --- Object cell + back-ref: ROWSET(1 row, schema ["x","x"]) whose row is
// [ OBJECT stdClass(id0), null ]; col1 overwrites col0 under key "x", then a
// TAG_REF points back at the displaced object. Pre-fix + unpinned = UAF. ---
$body =
    "\x02" . v(2) . v(1)."x" . v(8)."stdClass" .   // v2, dict = [x, stdClass]
    "\x07" . v(2) .                                // PACKED_MIXED, 2 elems
      "\x14" . v(1) . v(2) . v(0) . v(0) .         // ROWSET nrows=1 ncols=2 schema=[x,x]
        "\x0a" . v(1) . v(0) .                     //   row0 col0: OBJECT stdClass(1){}  (id0)
        "\x00" .                                   //   row0 col1: NULL (overwrites "x")
      "\x10" . v(0);                               // REF id0 -> displaced object
$r = phpser_unserialize_signed(sign($body,$key), $key);
var_dump(is_array($r) && count($r) === 2);         // no crash
var_dump(count($r[0]) === 1);                       // one row
var_dump(count($r[0][0]) === 1);                    // no phantom bucket: single key "x"
var_dump($r[0][0]['x']);                             // last column wins: null
var_dump($r[1] instanceof stdClass);                // back-ref resolves to the pinned object

// --- Scalar dup schema: count() must equal the unique-key count per row. ---
$body2 =
    "\x02" . v(1) . v(1)."c" .                     // v2, dict = [c]
    "\x14" . v(2) . v(2) . v(0) . v(0) .           // ROWSET nrows=2 ncols=2 schema=[c,c]
      "\x03" . chr(20) . "\x03" . chr(22) .        //   row0: c=10, c=11 -> last wins 11
      "\x03" . chr(40) . "\x03" . chr(42);         //   row1: c=20, c=21 -> last wins 21
$t = phpser_unserialize_signed(sign($body2,$key), $key);
var_dump(count($t) === 2 && count($t[0]) === 1 && count($t[1]) === 1);
var_dump($t[0]['c'] === 11 && $t[1]['c'] === 21);
echo "ok\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
NULL
bool(true)
bool(true)
bool(true)
ok
