--TEST--
phpser: ref counting — distinct zend_references stay distinct (counting_of_references.phpt)
--EXTENSIONS--
phpser
--FILE--
<?php

// PHP serialize() output for this shape is:
//   "a:4:{i:0;i:1;i:1;R:2;i:2;i:2;i:3;R:3;}"
// — two distinct refs ($ref1, $ref2), each shared between two slots.
// We must achieve the same semantic: [0]/[1] alias one zend_reference,
// [2]/[3] alias another, and the two refs are independent.

$ref1 = 1;
$ref2 = 2;
$arr = [&$ref1, &$ref1, &$ref2, &$ref2];

$rt = phpser_unserialize(phpser_serialize($arr));

// Initial values survive.
echo ($rt[0] === 1 && $rt[1] === 1 && $rt[2] === 2 && $rt[3] === 2)
    ? "init_vals OK\n" : "init_vals FAIL\n";

// Mutating slot 0 affects slot 1 only (same ref).
$rt[0] = 100;
echo ($rt[1] === 100 && $rt[2] === 2 && $rt[3] === 2)
    ? "ref1_shared OK\n" : "ref1_shared FAIL " . json_encode($rt) . "\n";

// Mutating slot 2 affects slot 3 only.
$rt[2] = 200;
echo ($rt[3] === 200 && $rt[0] === 100 && $rt[1] === 100)
    ? "ref2_shared OK\n" : "ref2_shared FAIL " . json_encode($rt) . "\n";

// --- Three-way refs: same ref shared between 3 array slots + 1 object prop. ---
$x = "alpha";
class T { public $v; }
$o = new T();
$o->v = &$x;
$pkg = ['a' => &$x, 'b' => &$x, 'c' => &$x, 'o' => $o];
$rt = phpser_unserialize(phpser_serialize($pkg));
$rt['a'] = "omega";
$ok = $rt['b'] === "omega" && $rt['c'] === "omega" && $rt['o']->v === "omega";
echo $ok ? "fourway_share OK\n" : "fourway_share FAIL " . var_export($rt, true) . "\n";

// --- Ref count semantics: same ref appearing N times should NOT inflate
// the wire format proportionally. We sanity-check that the wire payload
// for 10x-shared scales as O(1) extra per occurrence (varint of a tiny
// id), not O(N) per value bytes.
//
// Build: 1× ref encoding (TAG_NEW_REF + inner string) vs 10×:
//   10x size should be only ~10 bytes (varint(id) per repeat) bigger
//   than 1x. ---
$single = ["a" => &$x];
$x = "shared_string_value_long_enough_to_amplify_savings";
$single_arr = [&$x];
$multi_arr = [];
for ($i = 0; $i < 10; $i++) $multi_arr[] = &$x;

$s1 = phpser_serialize($single_arr);
$s2 = phpser_serialize($multi_arr);
// Each extra occurrence after the first should add ~2-3 bytes
// (TAG_REF + varint(id)), not the full string. Verify the delta is
// well under the string length × 9.
$delta_per_ref = (strlen($s2) - strlen($s1)) / 9.0;
echo ($delta_per_ref < 5) ? "ref_dedup OK\n" : "ref_dedup FAIL (delta_per_ref={$delta_per_ref})\n";

// --- Round-trip identity: encode + decode + encode again should yield
// a payload of equal or near-equal size (no growth from spurious
// duplication). ---
$rt2 = phpser_unserialize($s2);
$s3 = phpser_serialize($rt2);
echo (abs(strlen($s2) - strlen($s3)) < 5)
    ? "stable_size OK\n" : "stable_size FAIL (s2=" . strlen($s2) . " s3=" . strlen($s3) . ")\n";

?>
--EXPECT--
init_vals OK
ref1_shared OK
ref2_shared OK
fourway_share OK
ref_dedup OK
stable_size OK
