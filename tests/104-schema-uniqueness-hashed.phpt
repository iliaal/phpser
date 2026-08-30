--TEST--
phpser: schema uniqueness on the hashed branch (ncols > 32) — unique round-trips, forged dup rejected
--EXTENSIONS--
phpser
--FILE--
<?php
// dec_schema_keys_are_unique switches from the O(n^2) pairwise scan to a
// HashTable set above 32 keys. The trusted-path add_new decision now runs that
// check unconditionally (CR-004), so both branches must be exercised. Part A: a
// legit 40-column rowset (unique -> hashed set returns true -> add_new). Part B:
// a forged-but-signed 33-column TABLE whose schema repeats one key (hashed set
// returns false). A duplicate schema key exists only in handcrafted wire and
// routing it through zend_symtable_update walks an unbudgeted integer-domain
// hash chain (quadratic decode, BUG-R2-C2-A1-H1 / CWE-400), so the frame is
// now REJECTED at schema-parse time and the signed decoder throws (CR-008).
$key = str_repeat("k", 32);
function v($n){ $o=""; while($n>=0x80){ $o.=chr(($n&0x7f)|0x80); $n>>=7;} return $o.chr($n); }
function sign($b,$k){ return $b . hash_hmac('sha256',$b,$k,true); }

// --- Part A: 40 distinct string keys per row, signed round-trip == native ---
$rows = [];
for ($r = 0; $r < 3; $r++) {
    $row = [];
    for ($c = 0; $c < 40; $c++) $row["col$c"] = $r * 100 + $c;
    $rows[] = $row;
}
$back = phpser_unserialize_signed(phpser_serialize_signed($rows, $key), $key);
var_dump(serialize($back) === serialize($rows));   // 40-col unique -> add_new path
var_dump(count($back[0]) === 40);

// --- Part B: forged signed TABLE, 33 schema cols, col 32 repeats col 0 ---
$ncols = 33;
$dict = "";
for ($i = 0; $i < 32; $i++) $dict .= v(strlen("c$i")) . "c$i";   // dict = [c0..c31]
$schema = "";
for ($i = 0; $i < 32; $i++) $schema .= v($i);                    // schema keys c0..c31
$schema .= v(0);                                                 // 33rd key repeats c0
$cols = "";
for ($i = 0; $i < $ncols; $i++) {
    $val = $i * 10;
    // TAG_PACKED_LONGS column: tag byte + nrows cells (nrows=1 here, from the
    // TABLE header — no per-column count). One zigzag varint for the cell.
    $cols .= "\x08" . v($val << 1);
}
$body = "\x02" . v(32) . $dict . "\x15" . v(1) . v($ncols) . $schema . $cols;
try {
    phpser_unserialize_signed(sign($body, $key), $key);
    echo "dup_reject FAIL (no throw)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "failed to decode") !== false)
        ? "dup_reject OK\n" : "dup_reject FAIL (" . $e->getMessage() . ")\n";
}
echo "ok\n";
?>
--EXPECT--
bool(true)
bool(true)
dup_reject OK
ok
