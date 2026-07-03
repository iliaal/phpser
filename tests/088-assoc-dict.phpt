--TEST--
phpser: TAG_ASSOC_DICT wire v2 — dict-only assoc keys without per-key tag bytes
--EXTENSIONS--
phpser
--FILE--
<?php

function mk_rowset(int $rows): array {
    $out = [];
    for ($i = 0; $i < $rows; $i++) {
        $out[] = [
            'id' => $i,
            'user_id' => 1000 + ($i % 3),
            'name' => 'row_' . $i,
            'created_at' => '2026-05-19T12:00:00Z',
            'amount' => $i * 1.07,
            'active' => ($i % 2) === 0,
            'tags' => ['a', 'b', 'c'],
        ];
    }
    return $out;
}

$data = mk_rowset(5);
$blob = phpser_serialize($data);

echo ($blob[0] === "\x02") ? "version_v2 OK\n" : "version_v2 FAIL\n";

$rt = phpser_unserialize($blob);
echo (serialize($rt) === serialize($data)) ? "rowset_roundtrip OK\n" : "rowset_roundtrip FAIL\n";

// Heterogeneous packed rows skip TAG_ROWSET; row 3+ uses TAG_ASSOC_DICT once keys
// are dict-bound (loop-built rows share interned key pointers).
$warm = [];
$warm[] = ['id' => 0, 'name' => 'row_0'];
$warm[] = ['only' => 1];
for ($i = 1; $i < 4; $i++) {
    $warm[] = ['id' => $i, 'name' => 'row_' . $i];
}
$blob2 = phpser_serialize($warm);
echo (strpos($blob2, "\x13") !== false) ? "tag_assoc_dict OK\n" : "tag_assoc_dict FAIL\n";
echo (serialize(phpser_unserialize($blob2)) === serialize($warm))
    ? "warm_roundtrip OK\n" : "warm_roundtrip FAIL\n";

// Int-key assoc stays on TAG_ASSOC (0x06).
$intk = phpser_serialize(['a' => 1, 5 => 2]);
echo (strpos($intk, "\x06") !== false && strpos($intk, "\x13") === false)
    ? "int_key_stays_assoc OK\n" : "int_key_stays_assoc FAIL\n";

// Truncated TAG_ASSOC_DICT rejects cleanly.
echo (phpser_unserialize("\x02\x00\x13\x01\x00") === null) ? "trunc_reject OK\n" : "trunc_reject FAIL\n";
?>
--EXPECT--
version_v2 OK
rowset_roundtrip OK
tag_assoc_dict OK
warm_roundtrip OK
int_key_stays_assoc OK
trunc_reject OK