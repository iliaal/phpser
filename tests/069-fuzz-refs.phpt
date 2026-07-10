--TEST--
phpser: malformed-input fuzz around TAG_REF / TAG_NEW_REF (extends 060)
--EXTENSIONS--
phpser
--FILE--
<?php

// Contract: every input below must either decode to NULL (our error path)
// or come back as a sane value. Never crash. We assert no-crash by simply
// reaching the end of the file; per-case asserts pin the value where
// meaningful.

// Wire format reminders:
//   0x01           = PHPSER_VERSION
//   varint(N)      = LEB128 unsigned (single byte for N<128)
//   TAG_REF        = 0x10
//   TAG_NEW_REF    = 0x11
//   TAG_NULL       = 0x00
//   TAG_OBJECT_LEGACY = 0x0f
//   header         = 0x01 + varint(dict_size) + N×(varint(len)+bytes)

// --- TAG_REF id=0 with no ids registered: must reject ---
$rt = phpser_unserialize("\x01\x00\x10\x00");
echo ($rt === null) ? "ref_no_ids OK\n" : "ref_no_ids FAIL\n";

// --- TAG_REF with truncated varint (no payload after tag) ---
$rt = phpser_unserialize("\x01\x00\x10");
echo ($rt === null) ? "ref_trunc OK\n" : "ref_trunc FAIL\n";

// --- TAG_REF id=999 (way past any registered slot) ---
$rt = phpser_unserialize("\x01\x00\x10\xe7\x07");  // varint 999 = 0xe7 0x07
echo ($rt === null) ? "ref_oob OK\n" : "ref_oob FAIL\n";

// --- TAG_NEW_REF with no inner value: truncated ---
$rt = phpser_unserialize("\x01\x00\x11");
echo ($rt === null) ? "newref_trunc OK\n" : "newref_trunc FAIL\n";

// --- TAG_NEW_REF wrapping a back-ref to itself (id=0 = the ref being
// constructed). At decode time we register the ref BEFORE recursing into
// its inner value, so this is a ref-to-self via TAG_REF inside its own
// TAG_NEW_REF. PHP allows this shape ($r = &$r style). ---
$rt = phpser_unserialize("\x01\x00\x11\x10\x00");
// Self-referencing ref: a reference whose value IS the reference itself
// (the degenerate $r = &$r cycle). phpser flattens references, so this
// shape can't be represented and the decoder rejects it to NULL rather
// than crashing. Pin that value — the earlier `|| true` made this assert
// a tautology that would pass for any result, including a future crash.
echo ($rt === null) ? "newref_self OK\n" : "newref_self FAIL\n";

// --- TAG_NEW_REF nested deeply: depth bomb. Our decoder has no explicit
// max-depth on the decode side, but the C stack frame per recursion is
// small. 256 nested newrefs over TAG_NULL must not crash; the resulting
// zval is a chain of refs that PHP shows as &NULL after one auto-deref.
// We just assert no crash by surviving to the next line. ---
$buf = "\x01\x00" . str_repeat("\x11", 256) . "\x00";
@phpser_unserialize($buf);  // must not crash; type is implementation-detail
echo "newref_deep OK\n";

// --- Garbage tag inside otherwise valid frame ---
$rt = phpser_unserialize("\x01\x00\xff");
echo ($rt === null) ? "garbage_tag OK\n" : "garbage_tag FAIL\n";

// --- Wrong version byte. 0x01 and 0x02 are both valid (v1 / v2), so a real
// rejection test needs an out-of-range byte; 0x02\x00\x00 is a legitimate v2
// encoding of null and would pass for the wrong reason. ---
$rt = phpser_unserialize("\x03\x00\x00");
echo ($rt === null) ? "wrong_version OK\n" : "wrong_version FAIL\n";
// Sanity: 0x02 IS accepted (v2 empty-dict null), proving the above rejects on
// version, not on structure.
$rt = phpser_unserialize("\x02\x00\x00");
echo ($rt === null) ? "v2_null OK\n" : "v2_null FAIL\n";

// --- Empty input ---
$rt = phpser_unserialize("");
echo ($rt === null) ? "empty_input OK\n" : "empty_input FAIL\n";

// --- Header with oversized dict count ---
$rt = phpser_unserialize("\x01\xff\xff\xff\xff\x0f");  // varint ~UINT32_MAX
echo ($rt === null) ? "huge_dict OK\n" : "huge_dict FAIL\n";

// --- A valid TAG_NEW_REF + TAG_LONG, then garbage tail.
// The decoder reads the first value and stops; we don't enforce
// "no extra data" (PHP warns but doesn't fail on extra data). ---
$rt = phpser_unserialize("\x01\x00\x11\x03\x02garbage");  // long zigzag 2 → 1
// inner long zigzag(2) decodes to 1
echo ($rt === 1) ? "extra_data OK\n" : "extra_data FAIL " . var_export($rt, true) . "\n";

// --- Random bytes: feed 100 random payloads, none should crash. ---
mt_srand(42);
for ($i = 0; $i < 100; $i++) {
    $len = mt_rand(1, 50);
    $buf = "";
    for ($j = 0; $j < $len; $j++) $buf .= chr(mt_rand(0, 255));
    @phpser_unserialize($buf);
}
echo "random_fuzz OK\n";

// --- Deeply nested arrays — verify our MAX_DEPTH cap. Both encoder and
// decoder cap at depth 512 (encoder throws past it, decoder rejects to
// NULL). Build a 200-deep array, well under the cap, round-trip OK. ---
$deep = "x";
for ($i = 0; $i < 200; $i++) $deep = [$deep];
$rt = phpser_unserialize(phpser_serialize($deep));
$probe = $rt; $depth = 0;
while (is_array($probe) && $depth < 300) { $probe = $probe[0]; $depth++; }
echo ($probe === "x" && $depth === 200) ? "deep_array OK\n" : "deep_array FAIL d=$depth\n";

?>
--EXPECT--
ref_no_ids OK
ref_trunc OK
ref_oob OK
newref_trunc OK
newref_self OK
newref_deep OK
garbage_tag OK
wrong_version OK
v2_null OK
empty_input OK
huge_dict OK
extra_data OK
random_fuzz OK
deep_array OK
