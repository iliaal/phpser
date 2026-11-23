--TEST--
phpser: a hook growing top-level non-table ArrayObject storage cannot free the walked buffer
--DESCRIPTION--
Passing live ArrayObject storage directly to phpser_serialize() runs at depth 1.
A direct or nested __serialize() hook that appends to the ArrayObject resizes
its underlying HashTable in place without COW, freeing the original arPacked or
arData buffer. Cover dense packed, sparse packed, assoc, and assoc-with-holes
top-level shapes. Release builds only: debug builds abort on HT_ASSERT_RC1 in
the SPL write first.
--SKIPIF--
<?php
if (!extension_loaded("phpser")) die("skip phpser not loaded");
if (PHP_DEBUG) die("skip debug build aborts on HT_ASSERT_RC1 before the walk");
?>
--FILE--
<?php
class TopGrower298 {
    public static ?ArrayObject $ao = null;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) {
            self::$ao->append(str_repeat('x', 40) . $i);
        }
        return [];
    }
    public function __unserialize(array $data): void {}
}

// 1. Dense packed with nested hook
$ao1 = new ArrayObject([[new TopGrower298()], str_repeat('a', 40), str_repeat('b', 40)]);
TopGrower298::$ao = $ao1;
$s1 = phpser_serialize($ao1->__serialize()[1]);
$b1 = phpser_unserialize($s1);
var_dump(count($b1), $b1[1], $b1[2]);

// 2. Sparse packed (post-unset)
$sparse = [0 => new TopGrower298(), 1 => 'drop', 2 => str_repeat('c', 40)];
unset($sparse[1]);
$ao2 = new ArrayObject($sparse);
unset($sparse);
TopGrower298::$ao = $ao2;
$s2 = phpser_serialize($ao2->__serialize()[1]);
$b2 = phpser_unserialize($s2);
var_dump(array_keys($b2), $b2[2]);

// 3. Hole-free assoc
$ao3 = new ArrayObject(['first' => new TopGrower298(), 'second' => str_repeat('d', 40)]);
TopGrower298::$ao = $ao3;
$s3 = phpser_serialize($ao3->__serialize()[1]);
$b3 = phpser_unserialize($s3);
var_dump(array_keys($b3), $b3['second']);

// 4. Assoc with holes (post-unset)
$assoc = ['first' => new TopGrower298(), 'gone' => 1, 'last' => str_repeat('e', 40)];
unset($assoc['gone']);
$ao4 = new ArrayObject($assoc);
unset($assoc);
TopGrower298::$ao = $ao4;
$s4 = phpser_serialize($ao4->__serialize()[1]);
$b4 = phpser_unserialize($s4);
var_dump(array_keys($b4), $b4['last']);

TopGrower298::$ao = null;
?>
--EXPECT--
int(3)
string(40) "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
string(40) "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
array(2) {
  [0]=>
  int(0)
  [1]=>
  int(2)
}
string(40) "cccccccccccccccccccccccccccccccccccccccc"
array(2) {
  [0]=>
  string(5) "first"
  [1]=>
  string(6) "second"
}
string(40) "dddddddddddddddddddddddddddddddddddddddd"
array(2) {
  [0]=>
  string(5) "first"
  [1]=>
  string(4) "last"
}
string(40) "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"
