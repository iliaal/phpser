--TEST--
phpser: ENC_SNAP_BORROWED must not skip COW dup after an earlier hook drops the owner reference
--DESCRIPTION--
In TAG_OBJECT_SLOTS, taking a property snapshot adds 1 ref to each remaining
slot (ENC_SNAP_BORROWED). If an earlier property's __serialize() mutates the
DTO to drop its reference to a shared ArrayObject storage array, the storage
refcount falls from 3 to 2 (snapshot + ArrayObject). Treating refcount 2 as
unshared would skip zend_array_dup and let a nested hook grow the ArrayObject
in place, freeing the walked arPacked buffer. Release builds only: debug builds
abort on HT_ASSERT_RC1 in the SPL write first.
--SKIPIF--
<?php
if (!extension_loaded("phpser")) die("skip phpser not loaded");
if (PHP_DEBUG) die("skip debug build aborts on HT_ASSERT_RC1 before the walk");
?>
--FILE--
<?php
class Box297 {
    public $dropper;
    public $arr;
}
class Dropper297 {
    public static ?Box297 $box = null;
    public function __serialize(): array {
        self::$box->arr = null;
        return [];
    }
    public function __unserialize(array $data): void {}
}
class Grower297 {
    public static ?ArrayObject $ao = null;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) {
            self::$ao->append(str_repeat('z', 40) . $i);
        }
        return [];
    }
    public function __unserialize(array $data): void {}
}

$ao = new ArrayObject([new Grower297(), str_repeat('a', 40), str_repeat('b', 40)]);
Grower297::$ao = $ao;
$box = new Box297();
$box->dropper = new Dropper297();
$box->arr = $ao->__serialize()[1];
Dropper297::$box = $box;

$s = phpser_serialize($box);
Dropper297::$box = null;
Grower297::$ao = null;

$back = phpser_unserialize($s);
var_dump(count($back->arr), $back->arr[1], $back->arr[2]);
?>
--EXPECT--
int(3)
string(40) "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
string(40) "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
