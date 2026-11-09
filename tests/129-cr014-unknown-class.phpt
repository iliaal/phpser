--TEST--
phpser: plain, signed, and keyed decoding share missing-class fallbacks
--EXTENSIONS--
phpser
--INI--
phpser.key=unknown-class-test-key-0123456789abcdef
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

// Use the same valid object frames for each entry point, signing them with
// their documented framing rules. Missing classes are not a tag failure.
$key = ini_get('phpser.key');
$contextKey = hash_hmac('sha256', "phpser:redis:v1\0", $key, true);
enum PresentEnum { case One; }
$enumFrame = fn(string $class): string => "\x01\x02" . $e($class) . $e("One") . "\x0d\x00\x01";
$decoders = [
    'plain' => fn($frame) => phpser_unserialize($frame),
    'signed' => fn($frame) => phpser_unserialize_signed(
        $frame . hash_hmac('sha256', $frame, $key, true), $key),
    'keyed' => fn($frame) => phpser_unserialize_keyed(
        $frame . hash_hmac('sha256', $frame, $contextKey, true), 'redis'),
];
foreach ($decoders as $mode => $decode) {
    echo "$mode\n";

    // TAG_OBJECT for an unknown class: incomplete + preserved name + props kept.
    $p1 = "\x01" . "\x02" . $e("GoneA") . $e("v")
        . "\x0a\x00\x01" . "\x01" . "\x03\x02";
    $o1 = $decode($p1);
    $check($o1, "GoneA", "object");
    echo ((array)$o1)["v"] === 1 ? "object_prop OK\n" : "object_prop FAIL\n";

    // TAG_OBJECT_MAGIC for an unknown class: data array applied as props.
    $p2 = "\x01" . "\x02" . $e("GoneM") . $e("k")
        . "\x0e\x00"              // TAG_OBJECT_MAGIC class_idx=0
        . "\x06\x01"              // TAG_ASSOC n=1
        . "\x02\x01k"             // KEY_STR_INLINE "k"
        . "\x03\x04";             // LONG 2
    $o2 = $decode($p2);
    $check($o2, "GoneM", "magic");
    echo ((array)$o2)["k"] === 2 ? "magic_prop OK\n" : "magic_prop FAIL\n";

    // TAG_OBJECT_SLOTS for an unknown class: hollow incomplete, name preserved.
    $p3 = "\x02" . "\x01" . $e("GoneS")
        . "\x12\x00\x01"          // TAG_OBJECT_SLOTS class_idx=0 nprops=1
        . "\x03\x06";             // LONG 3 (consumed, dropped: no schema)
    $o3 = $decode($p3);
    $check($o3, "GoneS", "slots");
    echo array_keys((array)$o3) === ['__PHP_Incomplete_Class_Name']
        ? "slots_props_dropped OK\n" : "slots_props_dropped FAIL\n";
    // A back-ref to the hollow SLOTS object resolves to the same incomplete:
    // packed array [slots-obj, TAG_REF 0]; both elements must be the identical
    // named incomplete, proving the hollow object holds a live id.
    $p4 = "\x02" . "\x01" . $e("GoneS")
        . "\x07\x02"              // TAG_PACKED_MIXED n=2
        . "\x12\x00\x01"          // TAG_OBJECT_SLOTS class_idx=0 nprops=1
        . "\x03\x06"              // LONG 3 (consumed, dropped)
        . "\x10\x00";             // TAG_REF id=0
    $rt = $decode($p4);
    echo ($rt[0] === $rt[1]) ? "slots_ref_identity OK\n" : "slots_ref_identity FAIL\n";
    $check($rt[1], "GoneS", "slots_ref");
    // Missing legacy Serializable classes successfully decode to null, unlike
    // missing enums, which fail the decode (null only on the unsigned entry point).
    $legacy = "\x01\x01" . $e("GoneL") . "\x0f\x00\x02ok";
    echo $decode($legacy) === null ? "legacy_null OK\n" : "legacy_null FAIL\n";
    // The present-class control proves this is a valid enum frame, rather
    // than malformed bytes that happen to produce the same decode error.
    echo $decode($enumFrame(PresentEnum::class)) === PresentEnum::One
        ? "enum_known OK\n" : "enum_known FAIL\n";
    $enum = $enumFrame("GoneE");
    try {
        $value = $decode($enum);
        echo $mode === 'plain' && $value === null ? "enum_failure OK\n" : "enum_failure FAIL\n";
    } catch (Exception $error) {
        $expected = "phpser: signed payload failed to decode";
        echo $mode !== 'plain' && $error->getMessage() === $expected
            ? "enum_failure OK\n" : "enum_failure FAIL: " . $error->getMessage() . "\n";
    }
}
?>
--EXPECT--
plain
object OK
object_prop OK
magic OK
magic_prop OK
slots OK
slots_props_dropped OK
slots_ref_identity OK
slots_ref OK
legacy_null OK
enum_known OK
enum_failure OK
signed
object OK
object_prop OK
magic OK
magic_prop OK
slots OK
slots_props_dropped OK
slots_ref_identity OK
slots_ref OK
legacy_null OK
enum_known OK
enum_failure OK
keyed
object OK
object_prop OK
magic OK
magic_prop OK
slots OK
slots_props_dropped OK
slots_ref_identity OK
slots_ref OK
legacy_null OK
enum_known OK
enum_failure OK
