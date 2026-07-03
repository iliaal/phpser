--TEST--
phpser: TAG_ROWSET wire v2 — homogeneous assoc rows with schema emitted once
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
echo (strpos($blob, "\x14") !== false) ? "tag_rowset OK\n" : "tag_rowset FAIL\n";

$rt = phpser_unserialize($blob);
echo (serialize($rt) === serialize($data)) ? "rowset_roundtrip OK\n" : "rowset_roundtrip FAIL\n";

// Single-row packed assoc stays on TAG_PACKED_MIXED (schema not amortized).
$one = [['id' => 1, 'name' => 'x']];
$blob1 = phpser_serialize($one);
echo (strpos($blob1, "\x14") === false) ? "single_row_no_rowset OK\n" : "single_row_no_rowset FAIL\n";

// Heterogeneous keys fall back to PACKED_MIXED.
$mixed = [['id' => 1], ['name' => 'x']];
$blobm = phpser_serialize($mixed);
echo (strpos($blobm, "\x14") === false) ? "hetero_keys_no_rowset OK\n" : "hetero_keys_no_rowset FAIL\n";

// Truncated TAG_ROWSET rejects cleanly.
echo (phpser_unserialize("\x02\x00\x14\x01\x01\x00") === null) ? "trunc_reject OK\n" : "trunc_reject FAIL\n";
?>
--EXPECT--
version_v2 OK
tag_rowset OK
rowset_roundtrip OK
single_row_no_rowset OK
hetero_keys_no_rowset OK
trunc_reject OK