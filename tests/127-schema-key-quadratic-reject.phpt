--TEST--
phpser: schema-key install rejects numeric/duplicate keys — no unbudgeted integer-domain quadratic decode (BUG-R2-C2-A1-H1, CWE-400)
--DESCRIPTION--
The three schema-key container tags (TAG_ASSOC_DICT 0x13, TAG_ROWSET 0x14,
TAG_TABLE 0x15) gated an add_new fast path on "non-numeric && unique" and
otherwise fell back to zend_symtable_update. That fallback coerces canonical
numeric-string keys to INTEGER keys, whose bucket slot is h & (nTableSize-1)
with no hash function and no MAX_HASH_CHAIN_LENGTH budget. Because the
destination table is pre-sized to next_pow2(n) and never resizes, crafted keys
T, 2T, ..., nT all collapse into one slot: Theta(n^2) bucket walks from a
bounded-size frame — the exact quadratic-blowup class the security policy
excludes, and the class the 0.5.0 collision budget claimed to close. No honest
frame can reach the fallback (a real string-keyed bucket is never a canonical
numeric string, and array keys are unique), so dec_read_schema_keys now REJECTS
numeric or duplicate schema keys at schema-parse time, before any cell decode.
--EXTENSIONS--
phpser
--FILE--
<?php
function v($n){ $o=""; while($n>=0x80){ $o.=chr(($n&0x7f)|0x80); $n>>=7;} return $o.chr($n); }

// Build the worst-case colliding-key ASSOC_DICT frame the report used: keys
// T, 2T, ..., nT (canonical decimal strings) all congruent modulo the
// destination pre-size. Must reject, not burn quadratic CPU.
function collide_assoc_dict($n){
    $T = 1; while ($T < $n) $T <<= 1;
    $frame = "\x01" . v($n);              // v1, ndict = n
    for ($i = 1; $i <= $n; $i++) { $s = (string)($i * $T); $frame .= v(strlen($s)) . $s; }
    $frame .= "\x13" . v($n);             // TAG_ASSOC_DICT, n
    for ($i = 0; $i < $n; $i++) $frame .= v($i);   // key idx = dict index
    for ($i = 0; $i < $n; $i++) $frame .= "\x00";  // TAG_NULL values
    return $frame;
}

// A non-trivial n: pre-fix this decoded (and burned quadratic time); post-fix
// it must reject. Rejection is the security property and the regression guard:
// a fallback to the old collapse path would DECODE this to a valid array, so
// `=== null` catches it. (No wall-clock assertion — decode time is instrumented
// build and runner dependent; an ASAN lane rejects this same frame in seconds.)
$r = phpser_unserialize(collide_assoc_dict(16384));
echo ($r === null) ? "assoc_dict_reject OK\n" : "assoc_dict_reject FAIL\n";

// Single numeric key is enough to force the (former) fallback for the whole
// schema — reject.
$num1 = "\x01" . v(1) . v(1) . "7" . "\x13" . v(1) . v(0) . "\x00";
echo (phpser_unserialize($num1) === null) ? "single_numeric_reject OK\n" : "single_numeric_reject FAIL\n";

// Duplicate schema key -> reject.
$dup = "\x01" . v(1) . v(1) . "k" . "\x13" . v(2) . v(0) . v(0) . "\x00\x00";
echo (phpser_unserialize($dup) === null) ? "dup_reject OK\n" : "dup_reject FAIL\n";

// TAG_ROWSET numeric schema -> reject.
$rs = "\x01" . v(1) . v(1) . "5" . "\x14" . v(1) . v(1) . v(0) . "\x00";
echo (phpser_unserialize($rs) === null) ? "rowset_numeric_reject OK\n" : "rowset_numeric_reject FAIL\n";

// TAG_TABLE numeric schema -> reject.
$tb = "\x01" . v(1) . v(1) . "5" . "\x15" . v(1) . v(1) . v(0) . "\x08\x02";
echo (phpser_unserialize($tb) === null) ? "table_numeric_reject OK\n" : "table_numeric_reject FAIL\n";

// Sanity: an honest ASSOC_DICT with distinct non-numeric keys still decodes.
$ok = "\x01" . v(2) . v(1) . "a" . v(1) . "b" . "\x13" . v(2) . v(0) . v(1) . "\x03\x02\x03\x04";
$rok = phpser_unserialize($ok);
echo ($rok === ['a' => 1, 'b' => 2]) ? "honest_ok OK\n" : "honest_ok FAIL " . var_export($rok, true) . "\n";

// Sanity: a real array with an integer key round-trips (encoder never uses the
// schema-key path for it, so the reject cannot affect legitimate data).
$rt = phpser_unserialize(phpser_serialize([[5 => 1]]));
echo ($rt === [[5 => 1]]) ? "int_key_roundtrip OK\n" : "int_key_roundtrip FAIL " . var_export($rt, true) . "\n";
?>
--EXPECT--
assoc_dict_reject OK
single_numeric_reject OK
dup_reject OK
rowset_numeric_reject OK
table_numeric_reject OK
honest_ok OK
int_key_roundtrip OK
