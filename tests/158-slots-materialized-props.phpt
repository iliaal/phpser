--TEST--
phpser: objects keep TAG_OBJECT_SLOTS after their properties table is built
--DESCRIPTION--
get_object_vars(), foreach, var_export(), var_dump(), a dynamic-property
write followed by unset(), and clone of an object that has one all build the
object's properties HashTable. While that table only mirrors the live declared
slots, including a class whose redeclared inherited property leaves a dead
slot, the encoder must emit the same positional TAG_OBJECT_SLOTS bytes as for
an object that never had one. The md5s pin the untouched objects' wire bytes.
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

#[AllowDynamicProperties]
class Base {
    private string $secret = 'base-secret';
    protected array $tags = ['a', 'b'];
    private ?int $baseOnly = null;
    public function secret(): string { return $this->secret; }
    public function vars(): array { return get_object_vars($this); }
}

#[AllowDynamicProperties]
class Child extends Base {
    private string $secret = 'child-secret';
    public int $id = 7;
    public ?string $name = 'n';
    public bool $flag = false;
    public $untyped = [1, 2, 3];
    public readonly string $ro;
    public function __construct(public int $promoted = 5) { $this->ro = 'readonly'; }
}

function mk(): Child {
    $c = new Child(9);
    $c->id = 42;
    $c->untyped = ['k' => 'v', 'n' => [1.5, true]];
    return $c;
}

$fresh = phpser_serialize(mk());
echo 'fresh_tag ', top_tag($fresh) === 0x12 ? "OK\n" : "FAIL\n";
echo 'fresh_md5 ', md5($fresh), "\n";

$triggers = [
    'get_object_vars' => function (Child $o) { get_object_vars($o); },
    'scoped_vars'     => function (Child $o) { $o->vars(); },
    'foreach'         => function (Child $o) { foreach ($o as $v) {} },
    'var_export'      => function (Child $o) { var_export($o, true); },
    'var_dump'        => function (Child $o) { ob_start(); var_dump($o); ob_end_clean(); },
    'dyn_set_unset'   => function (Child $o) { $o->dyn = 1; unset($o->dyn); },
];
foreach ($triggers as $label => $touch) {
    $o = mk();
    $touch($o);
    $b = phpser_serialize($o);
    $rt = phpser_unserialize($b);
    $same = $b === $fresh;
    $rt_ok = $rt instanceof Child && serialize($rt) === serialize($o)
        && $rt->secret() === 'base-secret' && $rt->ro === 'readonly'
        && (array) $rt == (array) $o;
    echo str_pad($label, 16), ($same ? 'same' : 'DIFF'), ' ',
        top_tag($b) === 0x12 ? 'slots' : 'keyed', ' ',
        $rt_ok ? 'rt' : 'RT-FAIL', "\n";
}

// foreach by reference leaves refcount-1 PHP references in the slots; a
// shared reference in a slot must survive materialization unchanged too.
final class RefBox { public int $a = 1; public ?string $b = 'x'; public array $c = [1]; }
$plain = new RefBox;
$refd = new RefBox;
foreach ($refd as $k => &$v) {} unset($v);
$br = phpser_serialize($refd);
echo 'foreach_byref ', top_tag($br) === 0x12 ? 'slots' : 'keyed', ' ',
    serialize(phpser_unserialize($br)) === serialize($refd) ? 'rt' : 'RT-FAIL', "\n";
$x = 'shared';
$plain->b = &$x;
$bp = phpser_serialize([$plain, &$x]);
get_object_vars($plain);
$ba = phpser_serialize([$plain, &$x]);
$rr = phpser_unserialize($ba);
$rr[1] = 'changed';
echo 'ref_slot ', $bp === $ba ? 'same' : 'DIFF', ' ', $rr[0]->b === 'changed' ? 'ref-kept' : 'REF-LOST', "\n";

// A materialized object inside a graph keeps identity and the batch schema.
$shared = mk();
get_object_vars($shared);
$batch = [$shared, mk(), $shared];
$bb = phpser_serialize($batch);
$rb = phpser_unserialize($bb);
$fresh_shared = mk();
echo 'graph ', $bb === phpser_serialize([$fresh_shared, mk(), $fresh_shared]) ? 'same' : 'DIFF',
    ' ', $rb[0] === $rb[2] && $rb[0] !== $rb[1] ? 'identity' : 'NO-IDENTITY', "\n";

// Signed frames carry the same body.
$key = str_repeat('k', 32);
$m = mk();
get_object_vars($m);
echo 'signed ', phpser_serialize_signed($m, $key) === phpser_serialize_signed(mk(), $key)
    ? 'same' : 'DIFF', "\n";

// Typed defaults untouched by the constructor, and a class with no declared
// properties, behave the same way.
final class Defaults { public int $a = 1; public string $b = 'x'; public array $c = []; }
$d = new Defaults;
$bd = phpser_serialize($d);
get_object_vars($d);
echo 'defaults ', $bd === phpser_serialize($d) ? 'same' : 'DIFF', ' ',
    top_tag($bd) === 0x12 ? 'slots' : 'keyed', "\n";
$e = new stdClass;
$be = phpser_serialize($e);
get_object_vars($e);
echo 'empty_std ', $be === phpser_serialize($e) ? 'same' : 'DIFF', "\n";

// Redeclaring an inherited property leaves a NULL properties_info_table
// entry; the built table skips it, as the slots encoding does.
abstract class BaseModel {
    protected $table;
    protected $fillable = [];
    protected $casts = [];
    public $exists = false;
    protected $attributes = [];
}
class User extends BaseModel {
    protected $table = 'users';
    protected $fillable = ['name'];
    protected $casts = ['id' => 'int'];
    public function attrs(array $a): void { $this->attributes = $a; }
}
$u = new User;
$u->attrs(['id' => 3, 'name' => 'u']);
$bu = phpser_serialize($u);
get_object_vars($u);
$bu2 = phpser_serialize($u);
echo 'redeclared ', md5($bu), ' ', $bu === $bu2 ? 'same' : 'DIFF', ' ',
    top_tag($bu2) === 0x12 ? 'slots' : 'keyed', ' ',
    serialize(phpser_unserialize($bu2)) === serialize($u) ? 'rt' : 'RT-FAIL', "\n";

// A clone copies the source's table, INDIRECT buckets retargeted at its own
// slots.
$src = mk();
get_object_vars($src);
$cl = clone $src;
$bc = phpser_serialize($cl);
echo 'clone ', $bc === $fresh ? 'same' : 'DIFF', ' ', top_tag($bc) === 0x12 ? 'slots' : 'keyed', ' ',
    serialize(phpser_unserialize($bc)) === serialize($cl) ? 'rt' : 'RT-FAIL', "\n";
?>
--EXPECT--
fresh_tag OK
fresh_md5 0bb5b3e6fa59f2b825baa162181b1273
get_object_vars same slots rt
scoped_vars     same slots rt
foreach         same slots rt
var_export      same slots rt
var_dump        same slots rt
dyn_set_unset   same slots rt
foreach_byref slots rt
ref_slot same ref-kept
graph same identity
signed same
defaults same slots
empty_std same
redeclared a0418db373922871fbbf6362d589d814 same slots rt
clone same slots rt
