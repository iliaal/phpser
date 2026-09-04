--TEST--
phpser: CR-002 trailing bytes rejected on signed path, tolerated unsigned
--EXTENSIONS--
phpser
--FILE--
<?php
$key = "cr002-key";
$v = ["a" => 1, "b" => [1, 2]];
$p = phpser_serialize($v);

// Unsigned stays lenient on trailing garbage (historical behavior).
$rt = phpser_unserialize($p . "\x00\xff");
echo ($rt == $v) ? "unsigned_lenient OK\n" : "unsigned_lenient FAIL\n";

// Signed round-trip is unaffected.
$s = phpser_serialize_signed($v, $key);
echo (phpser_unserialize_signed($s, $key) == $v) ? "signed_valid OK\n" : "signed_valid FAIL\n";

// A suffixed frame re-signed under the same key must NOT verify: the HMAC
// would otherwise cover bytes the decoder never looks at.
$frame = substr($s, 0, -32);
$suffixed = $frame . "\x00";
$forged = $suffixed . hash_hmac("sha256", $suffixed, $key, true);
try {
    phpser_unserialize_signed($forged, $key);
    echo "signed_trailing FAIL (accepted)\n";
} catch (Exception $e) {
    echo (strpos($e->getMessage(), "failed to decode") !== false)
        ? "signed_trailing OK\n"
        : "signed_trailing FAIL (" . $e->getMessage() . ")\n";
}
?>
--EXPECT--
unsigned_lenient OK
signed_valid OK
signed_trailing OK
