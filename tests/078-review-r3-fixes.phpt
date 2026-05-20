--TEST--
phpser: round-3 review regressions — varint overflow + TAG_OBJECT_MAGIC data fallback
--EXTENSIONS--
phpser
--FILE--
<?php

// =====================================================================
// Finding #1: varint u64 overflow at shift=63. A crafted 10-byte varint
// 0x80 × 9 + 0x02 encodes 2^64; without the overflow guard, the slow
// path's `chunk << 63` silently wraps mod 2^64 to 0, aliasing dict
// index 0. Decoded payload returns dict[0] instead of rejecting.
// =====================================================================
$payload = "\x01"                           // version
         . "\x01"                            // dict_len=1
         . "\x01" . "a"                      // dict[0] = "a"
         . "\x05"                            // TAG_STR_DICT
         . "\x80\x80\x80\x80\x80\x80\x80\x80\x80\x02"; // varint 2^64
$rt = @phpser_unserialize($payload);
echo ($rt === null) ? "r3_varint_overflow_rejected OK\n"
                    : "r3_varint_overflow_rejected FAIL aliased=$rt\n";

// In-range 10-byte varint with byte_10=0x01 (encoding 2^63) must still
// be accepted — it's the maximum representable uint64 value bit.
// Actually 2^63 = 0x80×9 + 0x01. dict_idx 2^63 is out-of-bounds for
// our 1-entry dict, so we expect a clean "index out of range" reject,
// not a 2^64-aliased acceptance. Either rejection path is fine; the
// critical assertion is "no aliased acceptance".
$payload = "\x01"
         . "\x01"
         . "\x01" . "a"
         . "\x05"
         . "\x80\x80\x80\x80\x80\x80\x80\x80\x80\x01"; // varint 2^63
$rt = @phpser_unserialize($payload);
echo ($rt === null) ? "r3_varint_inrange_rejected OK\n"
                    : "r3_varint_inrange_rejected FAIL aliased=$rt\n";

// Continuation bit on the 10th byte must be rejected too (would
// imply an 11th byte, which exceeds u64 capacity).
$payload = "\x01"
         . "\x01"
         . "\x01" . "a"
         . "\x05"
         . "\x80\x80\x80\x80\x80\x80\x80\x80\x80\x81"; // 10th byte continued
$rt = @phpser_unserialize($payload);
echo ($rt === null) ? "r3_varint_11byte_rejected OK\n"
                    : "r3_varint_11byte_rejected FAIL\n";

// Normal varints (1-9 bytes) must still work.
$rt = phpser_unserialize(phpser_serialize(["k" => 123456789]));
echo ($rt["k"] === 123456789) ? "r3_varint_normal_works OK\n"
                              : "r3_varint_normal_works FAIL\n";

// =====================================================================
// Finding #2a: class with __serialize() but no __unserialize() was
// dropping the serialized state. Native PHP applies the data array as
// properties; phpser was silently ignoring it.
// =====================================================================
class Bag {
    public int $x = 0;
    public string $tag = "default";
    public function __serialize(): array {
        return ['x' => 42, 'tag' => 'serialized'];
    }
}
$o = new Bag();
$rt = phpser_unserialize(phpser_serialize($o));
echo ($rt->x === 42 && $rt->tag === 'serialized')
    ? "r3_magic_no_unser OK\n"
    : "r3_magic_no_unser FAIL x={$rt->x} tag={$rt->tag}\n";

// Same shape but with __unserialize present — must still defer to it.
class Bag2 {
    public int $x = 0;
    public function __serialize(): array { return ['x' => 100, '__seen' => true]; }
    public function __unserialize(array $d): void { $this->x = $d['x'] * 2; }
}
$rt = phpser_unserialize(phpser_serialize(new Bag2()));
echo ($rt->x === 200) ? "r3_magic_with_unser OK\n"
                      : "r3_magic_with_unser FAIL x={$rt->x}\n";

// =====================================================================
// Finding #2b: allowed_classes => false was dropping serialized props
// on incomplete-class instances.
// =====================================================================
class WithUns {
    public int $val = 0;
    public function __serialize(): array { return ['val' => 777, 'extra' => 'x']; }
    public function __unserialize(array $d): void { $this->val = $d['val']; }
}
$ser = phpser_serialize(new WithUns());
$inc = phpser_unserialize($ser, ['allowed_classes' => false]);
$inc_arr = (array)$inc;  // direct property access on Incomplete is warned
echo ($inc instanceof __PHP_Incomplete_Class
      && $inc_arr['val'] === 777
      && $inc_arr['extra'] === 'x')
    ? "r3_incomplete_preserves_data OK\n"
    : "r3_incomplete_preserves_data FAIL\n";

// allowed_classes => ['unrelated'] (allowlist that doesn't include WithUns)
// also produces incomplete-class with preserved data.
$inc2 = phpser_unserialize($ser, ['allowed_classes' => ['stdClass']]);
$inc2_arr = (array)$inc2;
echo ($inc2 instanceof __PHP_Incomplete_Class
      && $inc2_arr['val'] === 777)
    ? "r3_incomplete_allowlist OK\n"
    : "r3_incomplete_allowlist FAIL\n";

// allowed_classes => true is the no-filter case; should round-trip normally.
$ok = phpser_unserialize($ser, ['allowed_classes' => true]);
echo ($ok instanceof WithUns && $ok->val === 777)
    ? "r3_allowed_true OK\n"
    : "r3_allowed_true FAIL\n";

// =====================================================================
// Int-keyed __serialize entries become string-cast dynamic properties
// matching PHP's native behavior.
// =====================================================================
#[\AllowDynamicProperties]
class IntKey {
    public function __serialize(): array { return [0 => 'zero', 42 => 'meaning']; }
}
$rt = phpser_unserialize(phpser_serialize(new IntKey()));
echo ($rt->{"0"} === 'zero' && $rt->{"42"} === 'meaning')
    ? "r3_intkey_props OK\n"
    : "r3_intkey_props FAIL\n";

// =====================================================================
// Typed property type-mismatch in data array still rejected. The
// fallback path uses the same dec_install_prop helper as TAG_OBJECT.
// =====================================================================
class TypedBag {
    public int $n = 0;
    public function __serialize(): array { return ['n' => 'not-an-int']; }
}
$threw = false;
try {
    phpser_unserialize(phpser_serialize(new TypedBag()));
} catch (TypeError $e) {
    $threw = true;
}
echo $threw ? "r3_typed_mismatch_throws OK\n" : "r3_typed_mismatch_throws FAIL\n";

?>
--EXPECT--
r3_varint_overflow_rejected OK
r3_varint_inrange_rejected OK
r3_varint_11byte_rejected OK
r3_varint_normal_works OK
r3_magic_no_unser OK
r3_magic_with_unser OK
r3_incomplete_preserves_data OK
r3_incomplete_allowlist OK
r3_allowed_true OK
r3_intkey_props OK
r3_typed_mismatch_throws OK
