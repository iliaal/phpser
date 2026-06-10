--TEST--
phpser: direct property-slot decode path (declared / shadowed / dynamic mix)
--EXTENSIONS--
phpser
--FILE--
<?php

// The TAG_OBJECT decoder installs declared properties straight into
// OBJ_PROP slots via ce->properties_info, falling back to the materialized
// properties HT only for dynamic props and mangled-name mismatches. These
// shapes pin the fallback boundaries:
//   - shadowed privates: parent and child both declare private $x; the
//     child's properties_info maps "x" to the child's info, so the parent's
//     "\0A\0x" wire key must take the fallback and still land in the
//     parent's slot.
//   - declared-then-dynamic: the first dynamic key flips the object into
//     materialized mode mid-loop; later keys must keep installing correctly.
//   - same-class batch: the hot path the fast path exists for.

class A {
    private int $x = 1;
    public function getAX(): int { return $this->x; }
}
class B extends A {
    private string $x = 'b';
    public int $pub = 2;
    public function getBX(): string { return $this->x; }
}

class T {
    public int $i = 0;
    public ?string $s = null;
}

#[AllowDynamicProperties]
class Dyn {
    public int $declared = 1;
}

$fail = 0;
function rt(string $name, $v): void {
    global $fail;
    $r = phpser_unserialize(phpser_serialize($v));
    if (serialize($r) !== serialize($v)) {
        echo "MISMATCH $name\n  exp: " . serialize($v) . "\n  got: " . serialize($r) . "\n";
        $fail++;
    }
}

rt('shadowed_private', new B());

$b = phpser_unserialize(phpser_serialize(new B()));
if ($b->getAX() !== 1 || $b->getBX() !== 'b') {
    echo "MISMATCH shadowed slot values: A::x={$b->getAX()} B::x={$b->getBX()}\n";
    $fail++;
}

$m = new Dyn();
$m->dyn1 = 'd1';
$m->dyn2 = [1, 2];
rt('declared_then_dynamic', $m);

$batch = [];
for ($i = 0; $i < 50; $i++) {
    $o = new T();
    $o->i = $i;
    $o->s = "s$i";
    $batch[] = $o;
}
rt('same_class_batch', $batch);

echo $fail ? "FAILED $fail\n" : "OK\n";
?>
--EXPECT--
OK
