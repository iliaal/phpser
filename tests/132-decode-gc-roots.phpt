--TEST--
phpser: decode of a retained object graph adds no per-object GC roots
--EXTENSIONS--
phpser
--INI--
zend.enable_gc=1
--FILE--
<?php
// Releasing the id-table pins must not offer every decoded object to the cycle
// collector when all of them stay reachable from the returned value. The graph
// must still be collectable once the caller drops it.
class Row { public $id; public $name; public $tags; }
class TypedRow { public int $id; public string $name; public array $tags; }
class Node { public $parent; public $children = []; public $n; }
// Refcounted class defaults: the first slot write drops the object_init_ex copy.
enum Status { case A; case B; }
class WithEnum { public $id; public Status $s = Status::A; }
class WithArr { public $id; public array $tags = ['x', 'y']; public $plain = ['p']; }

function rows(string $cls, int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $r = new $cls();
        $r->id = $i;
        $r->name = "row$i";
        $r->tags = ['a', 'b'];
        $out[] = $r;
    }
    return $out;
}

function tree(int $n): Node {
    $root = new Node();
    $root->n = -1;
    for ($i = 0; $i < $n; $i++) {
        $c = new Node();
        $c->n = $i;
        $c->parent = $root;
        $root->children[] = $c;
    }
    return $root;
}

$key = str_repeat("k", 32);

function roots_added(callable $decode, &$keep): int {
    gc_collect_cycles();
    $before = gc_status()['roots'];
    $keep = $decode();
    return gc_status()['roots'] - $before;
}

$cases = [
    'untyped_1000' => phpser_serialize(rows('Row', 1000)),
    'typed_1000'   => phpser_serialize(rows('TypedRow', 1000)),
    'cyclic_500'   => phpser_serialize(tree(500)),
];
$x = new Node();
$x->n = 'shared';
$refs = [];
for ($i = 0; $i < 300; $i++) {
    $o = new Row();
    $o->id = $i;
    $o->name = &$x->n;
    $o->tags = $x;
    $refs[] = $o;
}
$cases['refs_300'] = phpser_serialize($refs);
$ws = [];
$wa = [];
for ($i = 0; $i < 1000; $i++) {
    $o = new WithEnum();
    $o->id = $i;
    if ($i % 2) $o->s = Status::B;
    $ws[] = $o;
    $o = new WithArr();
    $o->id = $i;
    if ($i % 2) { $o->tags = ['t', (string)$i]; $o->plain = [$i]; }
    $wa[] = $o;
}
$cases['enum_default_1000'] = phpser_serialize($ws);
$cases['array_default_1000'] = phpser_serialize($wa);

foreach ($cases as $name => $payload) {
    $d = roots_added(fn() => phpser_unserialize($payload), $keep);
    echo "$name unsigned roots<10: ", var_export($d < 10, true), "\n";
    $signed = phpser_serialize_signed(phpser_unserialize($payload), $key);
    $d = roots_added(fn() => phpser_unserialize_signed($signed, $key), $keep2);
    echo "$name signed roots<10: ", var_export($d < 10, true), "\n";
    unset($keep, $keep2);
}

// The retained graph is intact and still collectable after the caller drops it.
$t = phpser_unserialize($cases['cyclic_500']);
$ok = count($t->children) === 500;
foreach ($t->children as $i => $c) {
    if ($c->parent !== $t || $c->n !== $i) { $ok = false; break; }
}
echo "tree intact: ", var_export($ok, true), "\n";
unset($t, $c);
echo "tree collected>=501: ", var_export(gc_collect_cycles() >= 501, true), "\n";

$r = phpser_unserialize($cases['refs_300']);
$r[0]->name = 'changed';
echo "ref shared: ", var_export($r[299]->name === 'changed' && $r[299]->tags === $r[0]->tags, true), "\n";
$r[0]->tags->parent = $r;
unset($r);
echo "ref graph collected: ", var_export(gc_collect_cycles() > 0, true), "\n";
echo "roots left: ", gc_status()['roots'], "\n";
?>
--EXPECT--
untyped_1000 unsigned roots<10: true
untyped_1000 signed roots<10: true
typed_1000 unsigned roots<10: true
typed_1000 signed roots<10: true
cyclic_500 unsigned roots<10: true
cyclic_500 signed roots<10: true
refs_300 unsigned roots<10: true
refs_300 signed roots<10: true
enum_default_1000 unsigned roots<10: true
enum_default_1000 signed roots<10: true
array_default_1000 unsigned roots<10: true
array_default_1000 signed roots<10: true
tree intact: true
tree collected>=501: true
ref shared: true
ref graph collected: true
roots left: 0
