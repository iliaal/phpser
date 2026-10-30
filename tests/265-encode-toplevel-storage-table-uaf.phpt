--TEST--
phpser: a hook deleting a later row of top-level ArrayObject storage cannot free a gathered table row
--DESCRIPTION--
Test 126 covers live ArrayObject storage reached through __serialize(). The
same storage can also be the top-level argument: enc_try_table gathered
pointers into its RC-1 rows without pinning them, so a hook that deleted a
later row freed cells the column loop still read. The hook then allocates
same-shaped rows so a dangling read returns their bytes instead of the stale
ones, failing the expected output without a memory checker. Release builds
only: debug builds abort on HT_ASSERT_RC1 in the SPL write first.
--SKIPIF--
<?php
if (!extension_loaded("phpser")) die("skip phpser not loaded");
if (PHP_DEBUG) die("skip debug build aborts on HT_ASSERT_RC1 before the walk");
?>
--FILE--
<?php
class Cell {
    public static ?ArrayObject $ao = null;
    public function __serialize(): array {
        self::$ao->offsetUnset(2);
        $GLOBALS['reclaim'] = [];
        for ($j = 0; $j < 8; $j++) {
            $GLOBALS['reclaim'][] = ['h' => -$j, 'v' => str_repeat('z', 40) . $j];
        }
        return [];
    }
    public function __unserialize(array $data): void {}
}
$rows = [['h' => new Cell(), 'v' => str_repeat('a', 40)]];
for ($i = 1; $i < 4; $i++) {
    $rows[] = ['h' => $i, 'v' => str_repeat('b', 40) . $i];
}
$ao = new ArrayObject($rows);
unset($rows);
Cell::$ao = $ao;
$storage = $ao->__serialize()[1];
$s = phpser_serialize($storage);
Cell::$ao = null;
$back = phpser_unserialize($s);
var_dump(count($back), $back[2]['v']);
?>
--EXPECT--
int(4)
string(41) "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2"
