--TEST--
phpser: CR-007 wire magic member cannot override an incomplete-class name
--EXTENSIONS--
phpser
--FILE--
<?php
// Spoof is deliberately defined: if the wire name overwrote the magic
// member, the round trip below would resurrect a live Spoof instance and
// the no_resurrect oracle would fail. (Without the class, broken output
// would also decode to __PHP_Incomplete_Class and the check would pass
// on broken code.)
class Spoof { public $x = 0; }
// A crafted TAG_OBJECT for a denied class carrying a property literally
// named __PHP_Incomplete_Class_Name must not overwrite the engine-set
// original name: the decoder drops it, so a later re-encode cannot be
// tricked into emitting (and permissively resurrecting) an attacker class.
$e = function (string $s): string { return chr(strlen($s)) . $s; };
$dict = $e("Ghost") . $e("__PHP_Incomplete_Class_Name") . $e("x");
$payload = "\x01" . "\x03" . $dict
    . "\x0a\x00\x02"          // TAG_OBJECT class_idx=0 nprops=2
    . "\x01" . "\x0c\x05Spoof" // key 1 = "Spoof"
    . "\x02" . "\x03\x02";     // key 2 = 1

$inc = phpser_unserialize($payload, ["allowed_classes" => false]);
echo ($inc instanceof __PHP_Incomplete_Class) ? "incomplete OK\n" : "incomplete FAIL\n";
$props = (array)$inc;
echo ($props["__PHP_Incomplete_Class_Name"] ?? null) === "Ghost" ? "name_kept OK\n" : "name_kept FAIL\n";
echo ($props["x"] ?? null) === 1 ? "prop_kept OK\n" : "prop_kept FAIL\n";

// The laundering cycle fails: re-encode + permissive decode cannot
// resurrect Spoof from the dropped wire name.
$round = phpser_unserialize(phpser_serialize($inc));
echo ($round instanceof __PHP_Incomplete_Class) ? "no_resurrect OK\n" : "no_resurrect FAIL\n";
?>
--EXPECT--
incomplete OK
name_kept OK
prop_kept OK
no_resurrect OK
