--TEST--
phpser: encoder string cache — __serialize temporaries with non-interned repeated keys/values round-trip without corruption
--EXTENSIONS--
phpser
--FILE--
<?php
// Each object's __serialize() returns an array with non-interned keys ("a".$j)
// and values ("val_$i_$j"). The array is a temporary freed after the object is
// encoded; its string addresses get reused by the next object's strings. A
// content-hash cache seed keyed on a borrowed (non-interned) pointer would then
// alias, emitting the wrong dict index -> silent value corruption. Guard that
// the icache only seeds DICT slots for interned (address-stable) strings.
class Ser {
    public $i;
    public $d;
    public function __construct($i){ $this->i = $i; }
    public function __serialize(): array {
        $a = [];
        for ($j = 0; $j < 8; $j++) $a["a".$j] = "val_".$this->i."_".sprintf("%04d",$j);
        return $a;
    }
    public function __unserialize(array $a): void { $this->d = $a; }
}
$objs = [];
for ($i = 0; $i < 8; $i++) $objs[] = new Ser($i);

$back = phpser_unserialize(phpser_serialize($objs));
$fail = 0;
foreach ($back as $i => $o) {
    for ($j = 0; $j < 8; $j++) {
        $exp = "val_".$i."_".sprintf("%04d",$j);
        if (($o->d["a".$j] ?? null) !== $exp) $fail++;
    }
}
echo $fail === 0 ? "roundtrip OK\n" : "roundtrip FAIL ($fail mismatches)\n";
?>
--EXPECT--
roundtrip OK
