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
        // Warm up with the largest measured batch: the first collection that
        // frees a batch's worth of cycles grows the GC buffer once (native
        // unserialize and igbinary show the same one-off growth), and that is
        // not a per-decode leak.
        $batches = [300, 600];
        for ($i = 0; $i < max($batches); $i++) $decode();
        gc_collect_cycles();
        $before = memory_get_usage();
        $collected_before = gc_status()['collected'];
        // Two batches: 300 decodes, then 600 more. Growth is 0 once warm, so
        // a 4 KiB cumulative bound over 900 decodes catches ~4.5 B/decode.
        $growth = [];
        foreach ($batches as $batch) {
            for ($i = 0; $i < $batch; $i++) {
                $v = $decode();
                unset($v);
            }
            gc_collect_cycles();
            $growth[] = memory_get_usage() - $before;
        }
        $collected = gc_status()['collected'] - $collected_before;
        printf("%s/%s: growth<4KiB after 300=%s after 900=%s cycles_collected=%s\n", $label, $mode,
            var_export($growth[0] < 4096, true), var_export($growth[1] < 4096, true),
            in_array($label, ['cyclic_tree', 'self_ref_array'], true)
                ? var_export($collected > 0, true) : 'n/a');
        if ($growth[1] >= 4096) echo "  growth=", implode('/', $growth), " bytes after 300/900 decodes\n";
    }
}

// The decoded cycle is a real cycle: once unreachable, only the collector frees it.
$tree = phpser_unserialize(phpser_serialize($cases['cyclic_tree']));
var_dump($tree->children[3]->children[1]->parent->parent === $tree);
unset($tree);
var_dump(gc_collect_cycles() >= 81);
?>
--EXPECT--
wallet/unsigned: growth<4KiB after 300=true after 900=true cycles_collected=n/a
wallet/signed: growth<4KiB after 300=true after 900=true cycles_collected=n/a
eloquent_coll_30/unsigned: growth<4KiB after 300=true after 900=true cycles_collected=n/a
eloquent_coll_30/signed: growth<4KiB after 300=true after 900=true cycles_collected=n/a
cyclic_tree/unsigned: growth<4KiB after 300=true after 900=true cycles_collected=true
cyclic_tree/signed: growth<4KiB after 300=true after 900=true cycles_collected=true
self_ref_array/unsigned: growth<4KiB after 300=true after 900=true cycles_collected=true
self_ref_array/signed: growth<4KiB after 300=true after 900=true cycles_collected=true
bool(true)
bool(true)
