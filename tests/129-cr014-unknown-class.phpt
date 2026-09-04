--TEST--
phpser: CR-014 allowed-but-unknown classes decode to named incompletes
--EXTENSIONS--
phpser
--FILE--
<?php
$e = function (string $s): string { return chr(strlen($s)) . $s; };
$check = function ($o, string $name, string $which): void {
    if (!($o instanceof __PHP_Incomplete_Class)) {
        echo "$which FAIL (not incomplete)\n";
        return;
    }
    $props = (array)$o;
    echo (($props["__PHP_Incomplete_Class_Name"] ?? null) === $name)
        ? "$which OK\n" : "$which FAIL (name lost)\n";
};

// TAG_OBJECT for an unknown class: incomplete + preserved name + props kept.
$p1 = "\x01" . "\x02" . $e("GoneA") . $e("v")
    . "\x0a\x00\x01" . "\x01" . "\x03\x02";
$o1 = phpser_unserialize($p1);
$check($o1, "GoneA", "object");
echo ((array)$o1)["v"] === 1 ? "object_prop OK\n" : "object_prop FAIL\n";

// TAG_OBJECT_MAGIC for an unknown class: data array applied as props.
$p2 = "\x01" . "\x02" . $e("GoneM") . $e("k")
    . "\x0e\x00"              // TAG_OBJECT_MAGIC class_idx=0
    . "\x06\x01"              // TAG_ASSOC n=1
    . "\x02\x01k"             // KEY_STR_INLINE "k"
    . "\x03\x04";             // LONG 2
$o2 = phpser_unserialize($p2);
$check($o2, "GoneM", "magic");
echo ((array)$o2)["k"] === 2 ? "magic_prop OK\n" : "magic_prop FAIL\n";

// TAG_OBJECT_SLOTS for an unknown class: hollow incomplete, name preserved.
$p3 = "\x02" . "\x01" . $e("GoneS")
    . "\x12\x00\x01"          // TAG_OBJECT_SLOTS class_idx=0 nprops=1
    . "\x03\x06";             // LONG 3 (consumed, dropped: no schema)
$o3 = phpser_unserialize($p3);
$check($o3, "GoneS", "slots");
// A back-ref to the hollow SLOTS object resolves to the same incomplete:
// packed array [slots-obj, TAG_REF 0]; both elements must be the identical
// named incomplete, proving the hollow object holds a live id.
$p4 = "\x02" . "\x01" . $e("GoneS")
    . "\x07\x02"              // TAG_PACKED_MIXED n=2
    . "\x12\x00\x01"          // TAG_OBJECT_SLOTS class_idx=0 nprops=1
    . "\x03\x06"              // LONG 3 (consumed, dropped)
    . "\x10\x00";             // TAG_REF id=0
$rt = phpser_unserialize($p4);
echo ($rt[0] === $rt[1]) ? "slots_ref_identity OK\n" : "slots_ref_identity FAIL\n";
$check($rt[1], "GoneS", "slots_ref");
?>
--EXPECT--
object OK
object_prop OK
magic OK
magic_prop OK
slots OK
slots_ref_identity OK
slots_ref OK
