--TEST--
phpser: CR-008 denied+unloaded SLOTS is a hollow SUCCESS with a live id
--EXTENSIONS--
phpser
--FILE--
<?php
// A SLOTS frame for a class that is both denied and unloadable has no
// schema: values are consumed and dropped, but the object still decodes to
// SUCCESS (property-less incomplete) and claims its id, so a later TAG_REF
// resolves to the hollow object instead of failing the whole graph.
$e = function (string $s): string { return chr(strlen($s)) . $s; };
$payload = "\x02" . "\x01" . $e("HollowDTO")
    . "\x07\x02"              // TAG_PACKED_MIXED n=2
    . "\x12\x00\x01"          // TAG_OBJECT_SLOTS class_idx=0 nprops=1
    . "\x03\x0a"              // LONG 5 (consumed, then dropped)
    . "\x10\x00";             // TAG_REF id=0

$rt = phpser_unserialize($payload, ["allowed_classes" => false]);
echo is_array($rt) ? "root OK\n" : "root FAIL\n";
echo ($rt[0] instanceof __PHP_Incomplete_Class) ? "hollow OK\n" : "hollow FAIL\n";
$props = (array)$rt[0];
echo ($props["__PHP_Incomplete_Class_Name"] ?? null) === "HollowDTO" ? "name OK\n" : "name FAIL\n";
echo ($rt[1] === $rt[0]) ? "ref_resolves OK\n" : "ref_resolves FAIL\n";
?>
--EXPECT--
root OK
hollow OK
name OK
ref_resolves OK
