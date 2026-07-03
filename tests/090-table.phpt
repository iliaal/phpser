--TEST--
phpser: TAG_TABLE wire v2 — columnar homogeneous rowset with typed column runs
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
// Value body must start with TAG_TABLE (0x15) after the dict header.
$pos = 1;
$ndict = ord($blob[$pos++]);
for ($i = 0; $i < $ndict; $i++) {
    $len = ord($blob[$pos++]);
    $pos += $len;
}
echo ($blob[$pos] === "\x15") ? "tag_table OK\n" : "tag_table FAIL\n";

$rt = phpser_unserialize($blob);
echo (serialize($rt) === serialize($data)) ? "rowset_roundtrip OK\n" : "rowset_roundtrip FAIL\n";

// Single row stays off TAG_TABLE.
$one = [['id' => 1, 'name' => 'x']];
echo (strpos(phpser_serialize($one), "\x15") === false) ? "single_row_no_table OK\n" : "single_row_no_table FAIL\n";

// Truncated TAG_TABLE rejects cleanly.
echo (phpser_unserialize("\x02\x00\x15\x01\x01\x00") === null) ? "trunc_reject OK\n" : "trunc_reject FAIL\n";
?>
--EXPECT--
version_v2 OK
tag_table OK
rowset_roundtrip OK
single_row_no_table OK
trunc_reject OK