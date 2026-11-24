--TEST--
phpser: fast-slot partial decode failure cleanup and packed varint boundary rejection
--EXTENSIONS--
phpser
--FILE--
<?php
class FastSlotDto300 {
    public string $a;
    public int $b;
    public array $c;
    public function __construct(string $a, int $b, array $c) {
        $this->a = $a;
        $this->b = $b;
        $this->c = $c;
    }
}

// 1. Valid round-trip exercises DEC_CS_SLOTS_FAST.
$dto = new FastSlotDto300(str_repeat('k', 32), 42, [1, 2, 3]);
$bin = phpser_serialize($dto);
$out = phpser_unserialize($bin);
var_dump($out instanceof FastSlotDto300, $out->a === str_repeat('k', 32), $out->b, $out->c);

// 2. Truncated mid-object after refcounted slot 0 ($a) was decoded: must fail
// cleanly with no leaks or crashes (ASan/debug refcount check).
$trunc = substr($bin, 0, strlen($bin) - 4);
var_dump(@phpser_unserialize($trunc));

// 3. Type mismatch on slot 1 ($b expects int, wire supplies string) after slot 0
// holds a heap string: TAG_OBJECT_SLOTS(0x12), class_idx=0, nprops=3,
// TAG_STR_INLINE(0x0c) "heap", TAG_STR_INLINE(0x0c) "str", TAG_NULL(0x00).
$bad = "\x02\x01\x0eFastSlotDto300\x12\x00\x03"
     . "\x0c\x04heap"
     . "\x0c\x03str"
     . "\x00";
try {
    var_dump(@phpser_unserialize($bad));
} catch (TypeError $e) {
    echo "TypeError\n";
}

// 4. TAG_PACKED_LONGS (0x08) and TAG_PACKED_DELTA (0x16) boundary cases:
//    - valid 1/2/3/4/10-byte varints at exact end of buffer (short tail < 3 bytes)
//    - truncated 2-byte and 3-byte varints at end of buffer
//    - 10th-byte overflow (> 0x01) rejected
$longs = [0, 1, -1, 64, -65, 8192, -8193, 1048576, PHP_INT_MAX, PHP_INT_MIN, 7];
$s_longs = phpser_serialize($longs);
var_dump(phpser_unserialize($s_longs) === $longs);

// Truncated 2-byte varint in TAG_PACKED_LONGS (count=1, byte=0x80)
var_dump(@phpser_unserialize("\x02\x00\x08\x01\x80"));
// Truncated 3-byte varint in TAG_PACKED_LONGS (count=1, bytes=0x80 0x80)
var_dump(@phpser_unserialize("\x02\x00\x08\x01\x80\x80"));
// 10-byte overflow in TAG_PACKED_LONGS (10th byte = 0x02)
var_dump(@phpser_unserialize("\x02\x00\x08\x01" . str_repeat("\x80", 9) . "\x02"));
// Truncated 2-byte varint in TAG_PACKED_DELTA (count=2, v0=0x00, dv1=0x80)
var_dump(@phpser_unserialize("\x02\x00\x16\x02\x00\x80"));
// Truncated 3-byte varint in TAG_PACKED_DELTA (count=2, v0=0x00, dv1=0x80 0x80)
var_dump(@phpser_unserialize("\x02\x00\x16\x02\x00\x80\x80"));
// 10-byte overflow in TAG_PACKED_DELTA (count=1, 10th byte = 0x02)
var_dump(@phpser_unserialize("\x02\x00\x16\x01" . str_repeat("\x80", 9) . "\x02"));
?>
--EXPECT--
bool(true)
bool(true)
int(42)
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
NULL
TypeError
bool(true)
NULL
NULL
NULL
NULL
NULL
NULL
