--TEST--
phpser: malformed/truncated/random input safety (no crash, no read past buffer)
--EXTENSIONS--
phpser
--FILE--
<?php

// 1. Empty + version-only + truncated header
$malformed_static = [
    "",                                  // empty
    "\x00",                              // wrong version
    "\x01",                              // version only, no dict count
    "\x01\xff",                          // version + bogus varint cut off
    "\x01\xff\xff\xff\xff\xff\xff\xff\xff\xff", // varint > 64 bits
    "\x01\x01",                          // claims 1 dict entry but no entry
    "\x01\x01\x05",                      // claims 5-byte entry, no bytes
    "\x01\x01\x05abc",                   // partial dict entry
    "\x01\x00",                          // empty dict, no body
    "\x01\x00\x99",                      // empty dict + unknown tag
    "\x01\x00\x03",                      // TAG_LONG, no varint
    "\x01\x00\x04\x00\x00\x00",          // TAG_DOUBLE, only 3 bytes of payload
    "\x01\x00\x05\x05",                  // TAG_STR_DICT idx=5, dict empty
    "\x01\x00\x06\x05",                  // TAG_ASSOC len=5, no entries
    "\x01\x00\x07\xff\xff\xff\xff\xff",  // TAG_PACKED_MIXED len overflow
    "\x01\x00\x08\xff\xff\xff\xff\xff",  // TAG_PACKED_LONGS len overflow
    "\x01\x00\x09\xff\xff\xff\xff\xff",  // TAG_PACKED_DOUBLES len overflow
    "\x01\x00\x09\x02\x00\x00",          // TAG_PACKED_DOUBLES n=2 needs 16 bytes, only 2
    "\x01\x00\x0b\xff\xff\xff\xff\xff",  // TAG_PACKED_STRINGS len overflow
    "\x01\x00\x0b\x01\x63",              // TAG_PACKED_STRINGS n=1, dict_idx=99 (dict empty)
    "\x01\x00\x0b\x02\x00",              // TAG_PACKED_STRINGS n=2, idx-run truncated after one
    "\x01\x00\x0c\x05",                  // TAG_STR_INLINE len=5, no bytes
    "\x01\x00\x06\x01\x01\x63",          // TAG_ASSOC KEY_STR dict_idx=99 (dict empty)
    "\x01\x00\x0e\x00\x03",              // TAG_OBJECT_MAGIC class_idx=0 (dict empty) inner non-array
];

foreach ($malformed_static as $i => $bytes) {
    $rt = phpser_unserialize($bytes);
    // Just need to not crash; result can be NULL or partially decoded.
    if ($rt !== null && !is_scalar($rt) && !is_array($rt) && !is_object($rt)) {
        echo "case $i unexpected type\n";
    }
}
echo "static OK\n";

// 2. Truncate valid payloads at every length and decode each. Verifies
//    bounds checks on every read.
$valid_payloads = [
    phpser_serialize(null),
    phpser_serialize(42),
    phpser_serialize(3.14),
    phpser_serialize("hello world"),
    phpser_serialize([1, 2, 3]),
    phpser_serialize(["a" => 1, "b" => "two"]),
    phpser_serialize([[1, 2, 3], [4, 5, 6]]),
    phpser_serialize((object) ["x" => 1, "y" => "q"]),
    phpser_serialize([1.1, 2.2, 3.3, -0.0, INF]),          // TAG_PACKED_DOUBLES
    phpser_serialize([["a", "b", "c"], ["a", "b", "c"]]),  // row 2 -> TAG_PACKED_STRINGS
];

foreach ($valid_payloads as $bytes) {
    for ($len = 0; $len < strlen($bytes); $len++) {
        $rt = phpser_unserialize(substr($bytes, 0, $len));
        // Don't care what; just shouldn't segfault.
    }
}
echo "truncate OK\n";

// 3. Random byte fuzz seeded for reproducibility. Each iter substitutes a
//    chunk of random bytes mid-payload, then decodes.
mt_srand(42);
foreach ($valid_payloads as $bytes) {
    $len = strlen($bytes);
    for ($iter = 0; $iter < 50; $iter++) {
        $cut = mt_rand(0, $len - 1);
        $tail = '';
        for ($k = 0; $k < 30; $k++) $tail .= chr(mt_rand(0, 255));
        $rt = phpser_unserialize(substr($bytes, 0, $cut) . $tail);
    }
}
echo "fuzz OK\n";

// 4. Crafted object/enum tags that name an *allowed* but uninstantiable or
//    not-serializable class, or an invalid enum case. Each must decode to
//    NULL without crashing and without leaking a pending exception.
//    - TAG_ENUM (0x0d): class_idx, case_idx (dict indices).
//    - TAG_OBJECT (0x0a): class_idx, nprops=0.
enum Suit: string { case Hearts = "H"; const FOO = 1; }
abstract class AbstractFoo {}
interface IFoo {}
// Legacy Serializable (C-level ce->serialize, no __unserialize). Our encoder
// emits this as TAG_OBJECT_LEGACY; a TAG_OBJECT naming it is adversarial wire.
// @eval so the compile-time "Serializable is deprecated" notice stays local.
@eval('class LegacySer implements Serializable {
    public $x = 1;
    public function serialize(): string { return \serialize($this->x); }
    public function unserialize($data): void { $this->x = \unserialize($data); }
}');

$crafted = [
    // enum tag whose case-name slot is not a case at all
    "enum_missing_case"    => "\x01\x02\x04Suit\x08NotACase\x0d\x00\x01",
    // enum tag whose case-name slot is a real class constant but not a case
    "enum_noncase_const"   => "\x01\x02\x04Suit\x03FOO\x0d\x00\x01",
    // object tag naming a NOT_SERIALIZABLE class (corrupt-instance crash)
    "object_closure"       => "\x01\x01\x07Closure\x0a\x00\x00",
    // object tag naming an abstract class (object_init_ex would throw)
    "object_abstract"      => "\x01\x01\x0bAbstractFoo\x0a\x00\x00",
    // object tag naming an interface
    "object_interface"     => "\x01\x01\x04IFoo\x0a\x00\x00",
    // TAG_OBJECT naming a legacy Serializable class with no __unserialize:
    // native unserialize refuses this (raw props would bypass unserialize()).
    "object_serializable"  => "\x01\x01\x09LegacySer\x0a\x00\x00",
];
foreach ($crafted as $name => $bytes) {
    $rt = phpser_unserialize($bytes);
    if ($rt !== null) echo "$name expected NULL, got " . gettype($rt) . "\n";
    // Touch the result to surface a corrupt object that crashes on access.
    if (is_object($rt)) { try { var_export($rt); } catch (\Throwable $e) {} }
}
echo "crafted OK\n";

// 5. Numeric-string assoc keys must coerce to int exactly as native
//    unserialize. PHP arrays collapse a canonical numeric string key ("5")
//    to integer 5, so this state is unreachable from PHP code — only a
//    crafted payload can carry KEY_STR_INLINE "5". A raw zend_hash_update on
//    the untrusted path would preserve it as a string key, letting an
//    attacker smuggle a value past isset()/array_key_exists checks that
//    assume coercion already happened. Build the payloads by encoding a
//    1-char key we CAN construct, then byte-swapping it to the digit.
$one = phpser_serialize(["Z" => 42]);
$one[strpos($one, "Z")] = "5";
$r = phpser_unserialize($one);
$k = array_key_first($r);
echo (is_int($k) && $k === 5 && $r[5] === 42) ? "numkey_coerced OK\n" : "numkey_coerced FAIL\n";

// Dual int-5 and string-"5" in one payload must collapse to a single int
// key (last-write-wins), never a 2-element array holding both.
$dual = phpser_serialize([5 => 1, "Z" => 2]);
$dual[strpos($dual, "Z")] = "5";
$rd = phpser_unserialize($dual);
echo (count($rd) === 1 && $rd[5] === 2) ? "numkey_dual_collapse OK\n" : "numkey_dual_collapse FAIL\n";

// Non-canonical numeric strings ("05", leading zero) stay string keys, as
// native unserialize keeps them — confirms real coercion, not naive atoi.
$lead = phpser_serialize(["ZZ" => 9]);
$lead = str_replace("ZZ", "05", $lead);
$rl = phpser_unserialize($lead);
echo is_string(array_key_first($rl)) ? "numkey_leadzero_string OK\n" : "numkey_leadzero_string FAIL\n";

// 6. Wire v2 tags (0x12 OBJECT_SLOTS, 0x13 ASSOC_DICT, 0x14 ROWSET,
//    0x15 TABLE). Header version 0x02; dict ["a"] makes index 0 valid and 99
//    (0x63) an out-of-range dict reference. Every crafted payload must
//    fail-fast to NULL — no crash, no read past the buffer.
$H = "\x02\x01\x01a";
$v2 = [
    // OBJECT_SLOTS: class_idx varint missing / names an undefined class
    "slots_trunc"       => "\x02\x00\x12",
    "slots_badclass"    => "\x02\x01\x03Foo\x12\x00\x00",
    // ASSOC_DICT: key idx out of range / count overflow / truncated key run
    "assocdict_badidx"  => "\x02\x00\x13\x01\x05",
    "assocdict_novflow" => "\x02\x00\x13\xff\xff\xff\xff\xff",
    "assocdict_trunc"   => "{$H}\x13\x02\x00",
    // ROWSET: ncols=0 / column key idx out of range
    "rowset_ncols0"     => "\x02\x00\x14\x02\x00",
    "rowset_badidx"     => "\x02\x00\x14\x01\x01\x05",
    // TABLE: ncols=0 / column key idx out of range (untrusted copy path)
    "table_ncols0"      => "\x02\x00\x15\x02\x00",
    "table_badidx"      => "{$H}\x15\x01\x02\x00\x63\x07\x0c\x03xyz\x07\x0c\x03pqr",
];
foreach ($v2 as $name => $bytes) {
    $rt = phpser_unserialize($bytes);
    if ($rt !== null) echo "$name expected NULL, got " . gettype($rt) . "\n";
}
echo "v2 tags OK\n";

// 6a. TAG_TABLE nrows is bounded by the bytes that remain: a 14-byte payload
//     claiming 2^28 rows must reject, not attempt a multi-GB emalloc(nrows).
ini_set('memory_limit', '64M');
$dos = "{$H}\x15\x80\x80\x80\x80\x01\x01\x00\x08\x00"; // nrows=2^28, ncols=1, idx=0, LONGS
echo (phpser_unserialize($dos) === null) ? "table_nrows_dos OK\n" : "table_nrows_dos FAIL\n";

// 6b. Duplicate rowset/table schema keys on the unsigned path must keep
//     last-write-wins semantics. The optimized unique-schema path may use
//     add_new only after proving these are not duplicate keys.
$HD = "\x02\x02\x01a\x01a";
$dup_rowset = "{$HD}\x14\x01\x02\x00\x01\x03\x02\x03\x04"; // [['a'=>1, 'a'=>2]]
$dup_table  = "{$HD}\x15\x01\x02\x00\x01\x08\x02\x08\x04"; // same, columnar
echo (phpser_unserialize($dup_rowset) === [['a' => 2]]) ? "rowset_dup_schema OK\n" : "rowset_dup_schema FAIL\n";
echo (phpser_unserialize($dup_table) === [['a' => 2]]) ? "table_dup_schema OK\n" : "table_dup_schema FAIL\n";

// 6c. Trusted (signed) TAG_TABLE whose 2nd column index is out of range fails
//     after column 0's cell was already moved into the row. The error path
//     must not release that moved cell twice (release-silent; ASAN-detectable).
$key = "phpser-060-key";
$body = "{$H}\x15\x01\x02\x00\x63\x07\x0c\x03xyz\x07\x0c\x03pqr"; // nrows=1, ncols=2, idx=[0,99]
$frame = $body . hash_hmac('sha256', $body, $key, true);
echo (phpser_unserialize_signed($frame, $key) === null) ? "table_trusted_badidx OK\n" : "table_trusted_badidx FAIL\n";

?>
--EXPECT--
static OK
truncate OK
fuzz OK
crafted OK
numkey_coerced OK
numkey_dual_collapse OK
numkey_leadzero_string OK
v2 tags OK
table_nrows_dos OK
rowset_dup_schema OK
table_dup_schema OK
table_trusted_badidx OK
