--TEST--
phpser: decode-and-discard loop with the collector on keeps memory flat
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory accounting requires Zend allocator');
?>
--INI--
zend.enable_gc=1
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

class TreeNode {
    public ?TreeNode $parent = null;
    public array $children = [];
    public function __construct(public string $name) {}
}

function mk_cyclic_tree(int $fanout): TreeNode {
    $root = new TreeNode(distinct_string('root'));
    for ($i = 0; $i < $fanout; $i++) {
        $child = new TreeNode("child_$i");
        $child->parent = $root;
        $root->children[] = $child;
        for ($j = 0; $j < 3; $j++) {
            $leaf = new TreeNode("leaf_{$i}_$j");
            $leaf->parent = $child;
            $child->children[] = $leaf;
        }
    }
    return $root;
}

$arr = ['x' => 1];
$arr['self'] = &$arr;

$key = 'gc-key';
$cases = [
    'wallet' => mk_wallet(3, 4, 3),
    'eloquent_coll_30' => mk_eloquent_collection(30),
    'cyclic_tree' => mk_cyclic_tree(20),
    'self_ref_array' => $arr,
];
unset($arr);

gc_enable();
foreach ($cases as $label => $value) {
    foreach (['unsigned', 'signed'] as $mode) {
        $blob = $mode === 'signed' ? phpser_serialize_signed($value, $key) : phpser_serialize($value);
        $decode = $mode === 'signed'
            ? fn() => phpser_unserialize_signed($blob, $key)
            : fn() => phpser_unserialize($blob);
        // A full warm batch first: decode can grow the GC root buffer once
        // (~113 KiB for cyclic_tree unsigned), and that one-off growth is not
        // a per-decode leak.
        for ($i = 0; $i < 300; $i++) $decode();
        gc_collect_cycles();
        $before = memory_get_usage();
        $collected_before = gc_status()['collected'];
        for ($i = 0; $i < 300; $i++) {
            $v = $decode();
            unset($v);
        }
        $collected = gc_collect_cycles() + gc_status()['collected'] - $collected_before;
        $growth = memory_get_usage() - $before;
        printf("%s/%s: growth<16KiB=%s cycles_collected=%s\n", $label, $mode,
            var_export($growth < 16384, true),
            in_array($label, ['cyclic_tree', 'self_ref_array'], true)
                ? var_export($collected > 0, true) : 'n/a');
        if ($growth >= 16384) echo "  growth=$growth bytes over 300 decodes\n";
    }
}

// The decoded cycle is a real cycle: once unreachable, only the collector frees it.
$tree = phpser_unserialize(phpser_serialize($cases['cyclic_tree']));
var_dump($tree->children[3]->children[1]->parent->parent === $tree);
unset($tree);
var_dump(gc_collect_cycles() >= 81);
?>
--EXPECT--
wallet/unsigned: growth<16KiB=true cycles_collected=n/a
wallet/signed: growth<16KiB=true cycles_collected=n/a
eloquent_coll_30/unsigned: growth<16KiB=true cycles_collected=n/a
eloquent_coll_30/signed: growth<16KiB=true cycles_collected=n/a
cyclic_tree/unsigned: growth<16KiB=true cycles_collected=true
cyclic_tree/signed: growth<16KiB=true cycles_collected=true
self_ref_array/unsigned: growth<16KiB=true cycles_collected=true
self_ref_array/signed: growth<16KiB=true cycles_collected=true
bool(true)
bool(true)
