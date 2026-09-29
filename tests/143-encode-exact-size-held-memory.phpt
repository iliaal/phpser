--TEST--
phpser: an encoded payload is held at its own size, not a smart_str page
--SKIPIF--
<?php
if (!extension_loaded('phpser')) die('skip phpser not loaded');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_get_usage needs the Zend allocator');
// A custom MM handler (memprof and similar) can leave memory_get_usage()
// flat, which would make every held() reading 0 and pass vacuously.
$before = memory_get_usage();
$probe = [];
for ($i = 0; $i < 1000; $i++) {
    $probe[] = str_repeat('x', 100);
}
if (memory_get_usage() - $before < 100000) die('skip memory_get_usage does not track request allocations');
?>
--FILE--
<?php
gc_disable();

// Bytes retained per payload when N encodes are kept alive. The result
// array is pre-filled so its own growth is not counted.
function held(Closure $encode, int $n = 1000): float {
    $keep = array_fill(0, $n, null);
    $encode();
    $before = memory_get_usage();
    for ($i = 0; $i < $n; $i++) {
        $keep[$i] = $encode();
    }
    return (memory_get_usage() - $before) / $n;
}

// A held payload costs its bytes plus the zend_string header (and the debug
// allocator's block header), rounded up to an allocator bin, and bins above
// 128 bytes are at most 25% apart. The old smart_str sizing held 256 bytes for
// anything up to 231 and a 4096-byte page for the next few KB, which clears
// this bound at every size below. The lower bound rejects a reading that
// cannot hold the payload bytes at all.
function verdict(float $held, int $len): string {
    if ($held < $len) return "unmeasured ($held B for $len)";
    return $held <= ($len + 64) * 1.25 + 16 ? 'exact' : "bloated ($held B for $len)";
}

$cases = [
    'null'    => null,
    'int'     => 42,
    'str15'   => 'hello world abc',
    'assoc'   => ['id' => 42, 'name' => 'row', 'email' => 'a@b.c', 'active' => true, 'tags' => ['x', 'y']],
    'str300'  => str_repeat('x', 300),
    'str1000' => str_repeat('y', 1000),
];
foreach ($cases as $name => $value) {
    $len = strlen(phpser_serialize($value));
    printf("%-8s %s\n", $name, verdict(held(fn() => phpser_serialize($value)), $len));
}

// Exact sizing must not disturb ordinary string handling of the result.
$s = phpser_serialize('hello world abc');
$t = $s . $s;
var_dump(strlen($t), phpser_unserialize($s) === 'hello world abc');
?>
--EXPECT--
null     exact
int      exact
str15    exact
assoc    exact
str300   exact
str1000  exact
int(38)
bool(true)
