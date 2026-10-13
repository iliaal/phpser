--TEST--
phpser: apc.serializer=phpser stores plain phpser frames and round-trips arrays, objects, and references
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (!extension_loaded('apcu')) die('skip apcu not loaded');
?>
--INI--
apc.enabled=1
apc.enable_cli=1
apc.serializer=phpser
phpser.key=
--FILE--
<?php
ob_start();
(new ReflectionExtension('apcu'))->info();
$apcu = ob_get_clean();
ob_start();
(new ReflectionExtension('phpser'))->info();
$info = ob_get_clean();
echo "apcu_lists_phpser: ", preg_match('/Serialization Support => .*\bphpser\b/', $apcu) ? "yes" : "no", "\n";
echo "minfo_apcu: ", preg_match('/APCu serializer => (.*)/', $info, $m) ? $m[1] : "missing", "\n";
echo "minfo_key: ", preg_match('/keyed HMAC signing \(phpser\.key\) => (.*)/', $info, $m) ? $m[1] : "missing", "\n";

class Point {
    public function __construct(public int $x = 0, public ?Point $next = null) {}
}
class Hooked {
    public array $seen = [];
    public function __serialize(): array { return ['v' => 41]; }
    public function __unserialize(array $d): void { $this->seen[] = $d['v'] + 1; }
}

$shared = new Point(1);
$values = [
    'packed' => [1, 2, 3, 'x', 1.5, null, true],
    'assoc' => ['id' => 7, 'name' => 'alice', 'tags' => ['a', 'b']],
    'rows' => [['id' => 1, 'n' => 'a'], ['id' => 2, 'n' => 'b']],
    'object' => new Point(5, new Point(6)),
    'identity' => [$shared, $shared],
    'empty' => [],
];
foreach ($values as $name => $v) {
    apcu_store($name, $v);
    echo "$name: ", apcu_fetch($name) == $v ? "OK" : "FAIL", "\n";
}
$id = apcu_fetch('identity');
echo "identity_kept: ", $id[0] === $id[1] ? "OK" : "FAIL", "\n";
apcu_store('hooked', new Hooked);
echo "hook_ran: ", apcu_fetch('hooked')->seen === [42] ? "OK" : "FAIL", "\n";

// PHP references inside the stored array survive as references.
$a = [1];
$withRefs = ['x' => &$a, 'y' => &$a];
apcu_store('refs', $withRefs);
$r = apcu_fetch('refs');
$r['x'][] = 2;
echo "refs_kept: ", $r['y'] === [1, 2] ? "OK" : "FAIL", "\n";

// Without phpser.key APCu holds the plain frame: a string entry of the same
// length takes the same shared memory.
function mem_size(string $key): int {
    foreach (apcu_cache_info()['cache_list'] as $e) {
        if ($e['info'] === $key) return $e['mem_size'];
    }
    return -1;
}
$v = $values['rows'];
apcu_store('sized_v', $v);
apcu_store('sized_s', str_repeat('x', strlen(phpser_serialize($v))));
echo "plain_frame_size: ", mem_size('sized_v') === mem_size('sized_s') ? "OK" : mem_size('sized_v') . " vs " . mem_size('sized_s'), "\n";
echo "DONE\n";
?>
--EXPECT--
apcu_lists_phpser: yes
minfo_apcu: registered, active
minfo_key: disabled
packed: OK
assoc: OK
rows: OK
object: OK
identity: OK
empty: OK
identity_kept: OK
hook_ran: OK
refs_kept: OK
plain_frame_size: OK
DONE
