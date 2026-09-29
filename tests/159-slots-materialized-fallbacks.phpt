--TEST--
phpser: materialized property tables that differ from the declared slots stay keyed
--DESCRIPTION--
A properties table holding a dynamic property, an unset declared slot, or
buckets out of slot order is not a mirror of the declared slots, so the
encoder keeps keyed TAG_OBJECT and its bytes match the table walk (md5 pins).
Tables that return to a mirror state, hook-driven mutation mid-encode,
incomplete-class objects, and objects with custom serialization keep their
existing wire.
--EXTENSIONS--
phpser
--FILE--
<?php
function top_tag(string $blob): int {
    $p = 1;
    $rd = function () use ($blob, &$p): int {
        $v = 0; $shift = 0;
        do { $b = ord($blob[$p++]); $v |= ($b & 0x7f) << $shift; $shift += 7; } while ($b & 0x80);
        return $v;
    };
    $ndict = $rd();
    for ($i = 0; $i < $ndict; $i++) { $p += $rd(); }
    return ord($blob[$p]);
}
function native_rt(object $o): string {
    return serialize(unserialize(serialize($o)));
}
function tag(string $b): string {
    return match (top_tag($b)) { 0x12 => 'slots', 0x0a => 'keyed', default => sprintf('0x%02x', top_tag($b)) };
}

#[AllowDynamicProperties]
class Model {
    private string $secret = 's';
    public int $id = 1;
    public ?string $name = 'n';
    public $untyped = 'u';
    public array $list = [1, 2];
    public function secret(): ?string { return $this->secret ?? null; }
}

// Dynamic property: keyed, dynamic key survives.
$m = new Model;
$m->dyn = 'd';
$b = phpser_serialize($m);
$rt = phpser_unserialize($b);
echo 'dynamic ', tag($b), ' ', md5($b), ' ',
    $rt->dyn === 'd' && $rt->secret() === 's' && serialize($rt) === serialize($m) ? 'rt' : 'RT-FAIL', "\n";

// Unset typed declared slot after materialization: keyed, and the slot comes
// back with its default on decode, as with native unserialize().
$m = new Model;
get_object_vars($m);
unset($m->name);
$b = phpser_serialize($m);
$rt = phpser_unserialize($b);
echo 'unset_typed ', tag($b), ' ', md5($b), ' ',
    serialize($rt) === native_rt($m) ? 'rt' : 'RT-FAIL', "\n";

// Unset untyped declared slot after materialization.
$m = new Model;
foreach ($m as $v) {}
unset($m->untyped);
$b = phpser_serialize($m);
$rt = phpser_unserialize($b);
echo 'unset_untyped ', tag($b), ' ', md5($b), ' ',
    serialize($rt) === native_rt($m) ? 'rt' : 'RT-FAIL', "\n";

// Unset declared slot without a properties table: the unchanged keyed path.
$m = new Model;
unset($m->name);
$b = phpser_serialize($m);
echo 'unset_no_table ', tag($b), ' ', md5($b), "\n";

// Unset then reassigned: the table mirrors the slots again.
$fresh = phpser_serialize(new Model);
$m = new Model;
get_object_vars($m);
unset($m->name);
$m->name = 'n';
$b = phpser_serialize($m);
echo 'unset_reassign ', tag($b), ' ', $b === $fresh ? 'same' : 'DIFF', "\n";

// Dynamic property on top of an unset declared slot.
$m = new Model;
unset($m->id);
$m->extra = [1];
$b = phpser_serialize($m);
$rt = phpser_unserialize($b);
echo 'unset_plus_dyn ', tag($b), ' ', md5($b), ' ',
    $rt->extra === [1] && serialize($rt) === native_rt($m) ? 'rt' : 'RT-FAIL', "\n";

// stdClass with properties has only dynamic entries.
$s = new stdClass;
$s->a = 1;
$s->b = 'x';
$b = phpser_serialize($s);
echo 'stdclass ', tag($b), ' ', md5($b), "\n";

// ArrayObject over an object sorts the object's own table and leaves its
// buckets holding plain values instead of IS_INDIRECT slot pointers. 8.5
// deprecates the object backing store.
$m = new Model;
$ao = @new ArrayObject($m);
$ao->ksort();
unset($ao);
$b = phpser_serialize($m);
$rt = phpser_unserialize($b);
echo 'reordered ', tag($b), ' ', md5($b), ' ',
    serialize($rt) === native_rt($m) ? 'rt' : 'RT-FAIL', "\n";

// A redeclared inherited property leaves a dead slot, so the table has fewer
// buckets than slots; a dynamic property after the live slots must still
// keep the keyed path.
class A1 { public $y = 1; }
#[AllowDynamicProperties]
class B1 extends A1 { public $y = 2; }
$o = new B1;
get_object_vars($o);
$o->d = 'dynamic';
$b = phpser_serialize($o);
$rt = phpser_unserialize($b);
echo 'dead_slot_dyn ', tag($b), ' ', md5($b), ' ',
    is_object($rt) && $rt->d === 'dynamic' && serialize($rt) === native_rt($o) ? 'rt' : 'RT-FAIL', "\n";

// A hook that mutates its parent mid-encode sees the same tail snapshot with
// or without a properties table on the parent.
class Mutator {
    public $parent;
    public function __serialize(): array {
        $this->parent->tail = 'mutated';
        $this->parent->late = 'dyn';
        unset($this->parent->other);
        return ['k' => 1];
    }
    public function __unserialize(array $d): void {}
}
#[AllowDynamicProperties]
class Holder {
    public int $head = 1;
    public $child;
    public string $tail = 'orig';
    public string $other = 'o';
}
function mk_holder(): Holder {
    $h = new Holder;
    $h->child = new Mutator;
    $h->child->parent = $h;
    return $h;
}
$plain = phpser_serialize(mk_holder());
$h = mk_holder();
get_object_vars($h);
$mat = phpser_serialize($h);
$rt = phpser_unserialize($mat);
echo 'hook_mutation ', tag($plain), ' ', md5($plain), ' ', $plain === $mat ? 'same' : 'DIFF',
    ' ', $rt->tail, ' ', $rt->other, "\n";

// Incomplete-class objects hold only dynamic entries plus the name carrier.
class Orig { public int $a = 1; public string $b = 'x'; }
$payload = phpser_serialize(new Orig);
$ic = phpser_unserialize($payload, ['allowed_classes' => false]);
$b1 = phpser_serialize($ic);
get_object_vars($ic);
$b2 = phpser_serialize($ic);
$back = phpser_unserialize($b2);
echo 'incomplete ', tag($b1), ' ', $b1 === $b2 ? 'same' : 'DIFF', ' ',
    $back instanceof Orig && $back->b === 'x' ? 'rt' : 'RT-FAIL', "\n";

// Custom serialization is untouched by a built properties table.
$ao = new ArrayObject(['x' => 1, 'y' => [2]]);
foreach ($ao as $v) {}
get_object_vars($ao);
$b = phpser_serialize($ao);
echo 'arrayobject ', md5($b), ' ',
    phpser_unserialize($b)->getArrayCopy() === ['x' => 1, 'y' => [2]] ? 'rt' : 'RT-FAIL', "\n";
$st = new SplObjectStorage;
$st[new Orig] = 'data';
get_object_vars($st);
$b = phpser_serialize($st);
$rs = phpser_unserialize($b);
echo 'storage ', count($rs) === 1 && serialize($rs) === serialize($st) ? 'rt' : 'RT-FAIL', "\n";

// Backed property hooks (8.4+) read the backing slot either way. Lazy
// objects and hooks do not exist before 8.4, so older lanes print the
// expected line without running the check.
if (PHP_VERSION_ID >= 80400) {
    eval('class Hooked { public int $x = 3 { get => $this->x * 2; set => $value; } public string $y = "y"; }');
    $hk = new Hooked;
    $hb = phpser_serialize($hk);
    $a = (array) $hk;
    echo 'hooked ', tag($hb), ' ', $hb === phpser_serialize($hk) ? 'same' : 'DIFF', "\n";

    $ghost = (new ReflectionClass(Model::class))->newLazyGhost(function (Model $o) { $o->id = 9; });
    get_object_vars($ghost);
    $eager = new Model;
    $eager->id = 9;
    echo 'lazy ', phpser_serialize($ghost) === phpser_serialize($eager) ? 'same' : 'DIFF', "\n";
} else {
    echo "hooked slots same\n";
    echo "lazy same\n";
}
?>
--EXPECT--
dynamic keyed 6d7d6b90dbac996346cc3e709d384c22 rt
unset_typed keyed ac13fa599c7cd89584d594676352ce4f rt
unset_untyped keyed 67e13c1144c06956b9b6154734d143c4 rt
unset_no_table keyed ac13fa599c7cd89584d594676352ce4f
unset_reassign slots same
unset_plus_dyn keyed 34cc46e6dbac059b6c03edce08365645 rt
stdclass keyed 6b33bd8474fa271a602ac10649ae8cbd
reordered keyed e8e2aa74bdcd85a93d09bbec47d02e58 rt
dead_slot_dyn keyed 8e9dd266ab65d6f832f50e0af8595c31 rt
hook_mutation slots 652cb1c99b29012d751c5fbe6e610ab7 same orig o
incomplete keyed same rt
arrayobject 1c39b28d619b7904d044456b06eca385 rt
storage rt
hooked slots same
lazy same
