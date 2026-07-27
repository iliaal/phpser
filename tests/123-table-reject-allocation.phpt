--TEST--
phpser: a malformed TAG_TABLE rejects without allocating its claimed rows
--DESCRIPTION--
nrows is bounded only by "one wire byte per cell", so a frame that fails on
its first column must not have paid nrows row-HashTable allocations already.
Rows are materialized after the first column decodes; before that fix a ~1 MB
frame claiming a million rows exhausted a 64M limit and died with an
uncatchable E_ERROR instead of returning null.
--EXTENSIONS--
phpser
--INI--
memory_limit=64M
--FILE--
<?php
function vi(int $v): string {
    $o = '';
    while ($v >= 0x80) { $o .= chr(($v & 0x7f) | 0x80); $v >>= 7; }
    return $o . chr($v);
}

// TAG_TABLE (0x15): nrows=1e6, ncols=1, key_idx=0, then NUL column tags.
// 0x00 is not a valid column tag, so column 0 rejects immediately. The trailing
// padding only exists to satisfy the nrows <= remaining-bytes bound.
$rows = 1000000;
$frame = "\x02\x01\x01k"
       . "\x15" . vi($rows) . vi(1) . vi(0)
       . str_repeat("\x00", $rows);

echo strlen($frame) < 2 * 1024 * 1024 ? "payload under 2 MiB\n" : "payload too large\n";

// The assertion is that this line is REACHED. Eager row allocation needs about
// 88 MB for a million rows, so before the fix the decode died here with an
// uncatchable "Allowed memory size exhausted" and the rest of the file never
// ran. Only one column buffer is live now, so the frame rejects inside the
// limit. No absolute peak threshold: ASAN redzones and 32-bit builds move it.
echo phpser_unserialize($frame) === null ? "rejected null\n" : "NOT rejected\n";

// A well-formed table of the same shape still decodes.
$ok = [];
for ($i = 0; $i < 4; $i++) {
    $ok[] = ['id' => $i, 'name' => 'n' . ($i % 2)];
}
var_dump(phpser_unserialize(phpser_serialize($ok)) === $ok);
?>
--EXPECT--
payload under 2 MiB
rejected null
bool(true)
