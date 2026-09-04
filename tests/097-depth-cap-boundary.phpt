--TEST--
phpser: depth cap (512) — encode round-trips at the boundary, throws past it, decode rejects over-deep
--EXTENSIONS--
phpser
--FILE--
<?php

// Nest a scalar inside $d arrays. The innermost single-LONG array [42] encodes
// as TAG_PACKED_LONGS, so the scalar 42 never takes its own depth-guarded
// encode_value — the deepest guarded call is the innermost ARRAY. That array
// sits at depth $d-1, so $d = MAX_DEPTH (512) is the last value that fits and
// $d = 513 pushes the innermost array to depth 512 and trips the cap. (A
// non-packable leaf would shift this boundary by one; the point of the test is
// the fit/throw transition, not the exact literal.)
function nest(int $d) {
    $a = 42;
    for ($i = 0; $i < $d; $i++) { $a = [$a]; }
    return $a;
}

// 1. Exactly at the cap (MAX_DEPTH = 512): must round-trip, not truncate.
$s = phpser_serialize(nest(512));
$rt = phpser_unserialize($s);
echo ($rt !== null) ? "depth512 roundtrip OK\n" : "depth512 roundtrip FAIL\n";

// 2. One past the cap: encode MUST throw, never silently emit a TAG_NULL hole
//    (a truncated payload is undecodable -> silent total data loss).
try {
    phpser_serialize(nest(513));
    echo "depth513 throw FAIL (no exception)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "maximum nesting depth") !== false)
        ? "depth513 throw OK\n" : "depth513 throw FAIL: {$e->getMessage()}\n";
}

// 3. Signed encode enforces the same throw (the userland entry points all pass
//    throw_on_overflow=true).
try {
    phpser_serialize_signed(nest(1000), "k");
    echo "signed_deep throw FAIL (no exception)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "maximum nesting depth") !== false)
        ? "signed_deep throw OK\n" : "signed_deep throw FAIL: {$e->getMessage()}\n";
}

// 4. Decode-side cap: a crafted payload nested past the cap must reject to NULL,
//    not blow the C stack. Chain of TAG_PACKED_MIXED (0x07) len=1 wrappers over a
//    trailing TAG_NULL, under a v1 empty-dict header.
$H = "\x01\x00";
$deep_ok  = $H . str_repeat("\x07\x01", 400) . "\x00"; // 400 deep -> decodes
$deep_bad = $H . str_repeat("\x07\x01", 1000) . "\x00"; // 1000 deep -> reject
echo (phpser_unserialize($deep_ok) !== null) ? "decode_deep_ok OK\n" : "decode_deep_ok FAIL\n";
echo (phpser_unserialize($deep_bad) === null) ? "decode_deep_reject OK\n" : "decode_deep_reject FAIL\n";

// 5. String-leaf encode boundary: leaf packability shifts the fit/throw edge
//    by one vs the int leaf above (nest(512) fits there). A non-packable
//    "leaf" string nests one level less before the innermost array trips
//    the cap — pin the empirical edge, not the mechanism.
function snest(int $d) {
    $a = "leaf";
    for ($i = 0; $i < $d; $i++) { $a = [$a]; }
    return $a;
}
$s = phpser_serialize(snest(511));
$rt = phpser_unserialize($s);
$probe = $rt; $d = 0;
while (is_array($probe) && $d < 600) { $probe = $probe[0]; $d++; }
echo ($probe === "leaf" && $d === 511) ? "strleaf511 roundtrip OK\n" : "strleaf511 roundtrip FAIL d=$d\n";
try {
    phpser_serialize(snest(512));
    echo "strleaf512 throw FAIL (no exception)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "maximum nesting depth") !== false)
        ? "strleaf512 throw OK\n" : "strleaf512 throw FAIL: {$e->getMessage()}\n";
}

// 6. Decode-side cap under a v2 header: same fit/reject shape as (4) — the
//    version byte is a minimum-reader signal, not a second depth budget.
$H2 = "\x02\x00";
$v2_ok  = $H2 . str_repeat("\x07\x01", 400) . "\x00";
$v2_bad = $H2 . str_repeat("\x07\x01", 1000) . "\x00";
echo (phpser_unserialize($v2_ok) !== null) ? "decode_v2_deep_ok OK\n" : "decode_v2_deep_ok FAIL\n";
echo (phpser_unserialize($v2_bad) === null) ? "decode_v2_deep_reject OK\n" : "decode_v2_deep_reject FAIL\n";

// 7. NEW_REF chains collapse to their inner value, so a shallow chain is no
//    depth workout at all — but a 1000-deep chain over a LONG must still
//    reject to NULL (decode cap) warning-free, never a partial ref chain.
echo (phpser_unserialize("\x01\x00\x11\x03\x02") === 1) ? "newref_single OK\n" : "newref_single FAIL\n";
$warn097 = [];
set_error_handler(function (int $no, string $str) use (&$warn097): bool {
    $warn097[] = $str;
    return true;
});
$rt = phpser_unserialize("\x01\x00" . str_repeat("\x11", 1000) . "\x03\x02");
restore_error_handler();
echo ($rt === null && $warn097 === []) ? "newref_deep_reject OK\n" : "newref_deep_reject FAIL\n";
?>
--EXPECT--
depth512 roundtrip OK
depth513 throw OK
signed_deep throw OK
decode_deep_ok OK
decode_deep_reject OK
strleaf511 roundtrip OK
strleaf512 throw OK
decode_v2_deep_ok OK
decode_v2_deep_reject OK
newref_single OK
newref_deep_reject OK