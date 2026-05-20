--TEST--
phpser: regressions for issues.md CR-001/003/004/006/008/009
--EXTENSIONS--
phpser
--FILE--
<?php

// =====================================================================
// CR-001: typed-property invariant — crafted payload must not plant a
// string into an `int`-typed slot. Pre-fix this silently succeeded.
// =====================================================================
class T_int { public int $x = 0; }
// Hand-craft TAG_OBJECT with prop "x" = TAG_STR_INLINE "bad"
$payload = "\x01\x02"            // version + dict_len=2
         . "\x05T_int"            // dict[0] = "T_int"
         . "\x01x"                // dict[1] = "x"
         . "\x0a\x00\x01"          // TAG_OBJECT class=0 nprops=1
         . "\x01\x0c\x03bad";      // key_idx=1, TAG_STR_INLINE "bad"
$threw = false;
try {
    phpser_unserialize($payload);
} catch (TypeError $e) {
    $threw = str_contains($e->getMessage(), 'Cannot assign string')
          && str_contains($e->getMessage(), 'of type int');
}
echo $threw ? "cr001_typed_throws OK\n" : "cr001_typed_throws FAIL\n";

// Happy-path round-trip of a typed prop still works.
class T_ok { public int $n = 0; public string $s = ""; }
$o = new T_ok(); $o->n = 42; $o->s = "hi";
$rt = phpser_unserialize(phpser_serialize($o));
echo ($rt->n === 42 && $rt->s === "hi") ? "cr001_typed_roundtrip OK\n" : "cr001_typed_roundtrip FAIL\n";

// Typed reference: writes through the alias still type-check.
class T_ref { public int $x = 0; public $alias; }
$o = new T_ref();
$o->x = 7;
$o->alias = &$o->x;     // typed ref source
$rt = phpser_unserialize(phpser_serialize($o));
$threw = false;
try {
    $rt->alias = "nope";
} catch (TypeError $e) {
    $threw = true;
}
echo $threw ? "cr001_typed_ref OK\n" : "cr001_typed_ref FAIL\n";

// =====================================================================
// CR-003: dict index varint above UINT32_MAX must reject, not wrap to 0.
// LEB128 of 2^32 = 0x80 0x80 0x80 0x80 0x10. Build payload:
//   version + dict_len=1 + entry "x" + TAG_STR_DICT + varint(2^32)
// =====================================================================
$buf = "\x01\x01\x01x"           // header + dict=["x"]
     . "\x05"                     // TAG_STR_DICT
     . "\x80\x80\x80\x80\x10";    // varint(0x100000000)
$rt = @phpser_unserialize($buf);
echo ($rt === null) ? "cr003_wrap_rejected OK\n" : "cr003_wrap_rejected FAIL\n";

// Within-range still works.
$buf = "\x01\x01\x01x" . "\x05" . "\x00";
$rt = phpser_unserialize($buf);
echo ($rt === "x") ? "cr003_inrange OK\n" : "cr003_inrange FAIL\n";

// =====================================================================
// CR-004: duplicate assoc keys must collapse to the last value, not
// create a malformed HashTable with count != actual entries.
// =====================================================================
// Hand-craft TAG_ASSOC with two "a" keys.
$buf = "\x01\x00"                 // empty dict
     . "\x06\x02"                 // TAG_ASSOC count=2
     . "\x02\x01a\x03\x02"        // KEY_STR_INLINE "a" + TAG_LONG zigzag(1)
     . "\x02\x01a\x03\x04";       // KEY_STR_INLINE "a" + TAG_LONG zigzag(2)
$rt = phpser_unserialize($buf);
echo (is_array($rt) && count($rt) === 1 && $rt['a'] === 2) ? "cr004_dup_collapse OK\n" : "cr004_dup_collapse FAIL\n";

// Duplicate int keys same shape.
$buf = "\x01\x00"
     . "\x06\x02"
     . "\x00\x00\x03\x02"          // KEY_LONG 0 + TAG_LONG 1
     . "\x00\x00\x03\x06";          // KEY_LONG 0 + TAG_LONG 3
$rt = phpser_unserialize($buf);
echo (count($rt) === 1 && $rt[0] === 3) ? "cr004_dup_int_collapse OK\n" : "cr004_dup_int_collapse FAIL\n";

// =====================================================================
// CR-006: HMAC stack key wipe — can't directly observe, but verify the
// signing still round-trips. The wipe is defense-in-depth; tests just
// catch a build-level breakage of the wipe path.
// =====================================================================
$key = "test-secret-key-of-modest-length";
$sig = phpser_serialize_signed(['x' => 1], $key);
$rt = phpser_unserialize_signed($sig, $key);
echo ($rt === ['x' => 1]) ? "cr006_hmac_roundtrip OK\n" : "cr006_hmac_roundtrip FAIL\n";

// Long key (forces the K' = H(key) branch where ZEND_SECURE_ZERO covers
// the digest-derived material).
$longkey = str_repeat("a", 200);
$sig = phpser_serialize_signed(['x' => 1], $longkey);
$rt = phpser_unserialize_signed($sig, $longkey);
echo ($rt === ['x' => 1]) ? "cr006_long_key OK\n" : "cr006_long_key FAIL\n";

// =====================================================================
// CR-008: allowed_classes with non-string entry must throw TypeError,
// matching PHP's native unserialize behavior.
// =====================================================================
class C_ok {}
$ser = phpser_serialize(new C_ok());
$threw = false;
try {
    phpser_unserialize($ser, ['allowed_classes' => [123]]);
} catch (TypeError $e) {
    $threw = true;
}
echo $threw ? "cr008_nonstring_throws OK\n" : "cr008_nonstring_throws FAIL\n";

$threw = false;
try {
    phpser_unserialize($ser, ['allowed_classes' => [C_ok::class, null]]);
} catch (TypeError $e) {
    $threw = true;
}
echo $threw ? "cr008_null_throws OK\n" : "cr008_null_throws FAIL\n";

// Valid (all-strings) array still works.
$rt = phpser_unserialize($ser, ['allowed_classes' => [C_ok::class]]);
echo ($rt instanceof C_ok) ? "cr008_valid OK\n" : "cr008_valid FAIL\n";

// =====================================================================
// CR-009: doubles encode as little-endian per wire-format spec. We can't
// flip endianness at runtime, but we can verify the LE byte layout on a
// known value (all of x86 / ARM / WSL are LE so this test runs there).
// =====================================================================
$v = 3.14159265358979;
$ser = phpser_serialize($v);
$expected_le_bytes = pack('e', $v);     // 'e' = double LE
$tail = substr($ser, -8);
echo ($tail === $expected_le_bytes) ? "cr009_double_le OK\n" : "cr009_double_le FAIL\n";

// PACKED_DOUBLES same.
$arr = [1.5, 2.5, 3.5];
$ser = phpser_serialize($arr);
// Last 24 bytes are the three LE doubles.
$tail = substr($ser, -24);
$expect = pack('e', 1.5) . pack('e', 2.5) . pack('e', 3.5);
echo ($tail === $expect) ? "cr009_packed_doubles_le OK\n" : "cr009_packed_doubles_le FAIL\n";

// Round-trip of weird doubles (subnormal, NaN, Inf) still works.
$weird = [PHP_FLOAT_MIN, INF, -INF, NAN];
$rt = phpser_unserialize(phpser_serialize($weird));
echo ($rt[0] === PHP_FLOAT_MIN && $rt[1] === INF && $rt[2] === -INF && is_nan($rt[3]))
    ? "cr009_doubles_roundtrip OK\n" : "cr009_doubles_roundtrip FAIL\n";

?>
--EXPECT--
cr001_typed_throws OK
cr001_typed_roundtrip OK
cr001_typed_ref OK
cr003_wrap_rejected OK
cr003_inrange OK
cr004_dup_collapse OK
cr004_dup_int_collapse OK
cr006_hmac_roundtrip OK
cr006_long_key OK
cr008_nonstring_throws OK
cr008_null_throws OK
cr008_valid OK
cr009_double_le OK
cr009_packed_doubles_le OK
cr009_doubles_roundtrip OK
