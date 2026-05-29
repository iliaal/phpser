--TEST--
phpser: round-4 review fixes — encode depth-cap throws (no silent truncation); option TypeError names the calling function
--EXTENSIONS--
phpser
--FILE--
<?php
// =====================================================================
// Encode now FAILS LOUD when input nests deeper than the cap, instead of
// silently emitting a payload that decode rejects in full (NULL). The
// encode and decode caps are equal, so a truncated payload would have
// been undecodable — silent total data loss. Both encoders throw now.
// =====================================================================
$deep = "leaf";
for ($i = 0; $i < 1000; $i++) $deep = [$deep];

try {
    phpser_serialize($deep);
    echo "serialize_depth FAIL (no throw)\n";
} catch (\Exception $e) {
    echo str_contains($e->getMessage(), "maximum nesting depth")
        ? "serialize_depth OK\n" : "serialize_depth FAIL: {$e->getMessage()}\n";
}

try {
    phpser_serialize_signed($deep, "k");
    echo "serialize_signed_depth FAIL (no throw)\n";
} catch (\Exception $e) {
    echo str_contains($e->getMessage(), "maximum nesting depth")
        ? "serialize_signed_depth OK\n" : "serialize_signed_depth FAIL: {$e->getMessage()}\n";
}

// A legitimately-shallow structure still round-trips intact.
$ok = "leaf";
for ($i = 0; $i < 100; $i++) $ok = [$ok];
$rt = phpser_unserialize(phpser_serialize($ok));
$probe = $rt; $d = 0;
while (is_array($probe)) { $probe = $probe[0]; $d++; }
echo ($probe === "leaf" && $d === 100) ? "shallow_roundtrip OK\n" : "shallow_roundtrip FAIL d=$d\n";

// =====================================================================
// The allowed_classes TypeError names the CALLING function. Regression:
// the signed path used to report "phpser_unserialize()".
// =====================================================================
try {
    phpser_unserialize("\x01\x00\x00", ['allowed_classes' => [123]]);
    echo "unser_typeerror FAIL (no throw)\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}

$signed = phpser_serialize_signed([1, 2, 3], "key");
try {
    phpser_unserialize_signed($signed, "key", ['allowed_classes' => [123]]);
    echo "unser_signed_typeerror FAIL (no throw)\n";
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
serialize_depth OK
serialize_signed_depth OK
shallow_roundtrip OK
phpser_unserialize(): allowed_classes option must be an array of class names, int given
phpser_unserialize_signed(): allowed_classes option must be an array of class names, int given
