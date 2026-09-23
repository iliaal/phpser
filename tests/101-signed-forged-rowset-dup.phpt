--TEST--
phpser: signed (trusted) forged rowset/table with duplicate schema keys are rejected before any cell decode (no phantom buckets, no back-ref UAF)
--EXTENSIONS--
phpser
--FILE--
<?php
// Signed duplicate schemas must reject before decoding cells. HMAC verification
// proves neither key uniqueness nor safe numeric-key hash chains.
function v($n){ $o=""; while($n>=0x80){ $o.=chr(($n&0x7f)|0x80); $n>>=7;} return $o.chr($n); }
$key = str_repeat("k", 32);
function sign($body,$key){ return $body . hash_hmac('sha256',$body,$key,true); }

// --- Object cell + back-ref: ROWSET(1 row, schema ["x","x"]) whose row is
// [ OBJECT stdClass(id0), null ] then a TAG_REF back-ref. The duplicate "x"
// schema key is rejected before the object cell is ever decoded, so the frame
// fails to decode and the signed decoder throws. No object is
// registered, so the back-ref UAF class cannot arise. ---
$body =
    "\x02" . v(2) . v(1)."x" . v(8)."stdClass" .   // v2, dict = [x, stdClass]
    "\x07" . v(2) .                                // PACKED_MIXED, 2 elems
      "\x14" . v(1) . v(2) . v(0) . v(0) .         // ROWSET nrows=1 ncols=2 schema=[x,x]
        "\x0a" . v(1) . v(0) .                     //   row0 col0: OBJECT stdClass(1){}  (id0)
        "\x00" .                                   //   row0 col1: NULL (overwrites "x")
      "\x10" . v(0);                               // REF id0 -> displaced object
try {
    phpser_unserialize_signed(sign($body,$key), $key);
    echo "obj_dup FAIL (no throw)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "failed to decode") !== false)
        ? "obj_dup OK\n" : "obj_dup FAIL (" . $e->getMessage() . ")\n";
}

// --- Scalar dup schema: also rejected. ---
$body2 =
    "\x02" . v(1) . v(1)."c" .                     // v2, dict = [c]
    "\x14" . v(2) . v(2) . v(0) . v(0) .           // ROWSET nrows=2 ncols=2 schema=[c,c]
      "\x03" . chr(20) . "\x03" . chr(22) .        //   row0: c=10, c=11
      "\x03" . chr(40) . "\x03" . chr(42);         //   row1: c=20, c=21
try {
    phpser_unserialize_signed(sign($body2,$key), $key);
    echo "scalar_dup FAIL (no throw)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "failed to decode") !== false)
        ? "scalar_dup OK\n" : "scalar_dup FAIL (" . $e->getMessage() . ")\n";
}
echo "ok\n";
?>
--EXPECT--
obj_dup OK
scalar_dup OK
ok
