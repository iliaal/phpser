--TEST--
phpser: with phpser.key, APCu values carry the documented context-bound HMAC tag and still round-trip
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
phpser.key=apcu-kat-key-0123456789abcdef0123
--FILE--
<?php
$key = 'apcu-kat-key-0123456789abcdef0123';
ob_start();
(new ReflectionExtension('phpser'))->info();
$info = ob_get_clean();
echo "minfo_key: ", preg_match('/keyed HMAC signing \(phpser\.key\) => (.*)/', $info, $m) ? $m[1] : "missing", "\n";
echo "minfo_apcu: ", preg_match('/APCu serializer => (.*)/', $info, $m) ? $m[1] : "missing", "\n";

class Node {
    public $children = [];
    public function __construct(public string $name) {}
}
$root = new Node('root');
$root->children[] = new Node('leaf');
$root->children[] = $root;
$a = ['n' => 1];
$v = ['tree' => $root, 'r1' => &$a, 'r2' => &$a, 'list' => range(1, 20)];
var_dump(apcu_store('graph', $v));
$got = apcu_fetch('graph', $ok);
var_dump($ok);
echo "cycle_kept: ", $got['tree']->children[1] === $got['tree'] ? "OK" : "FAIL", "\n";
$got['r1']['n'] = 2;
echo "refs_kept: ", $got['r2']['n'] === 2 ? "OK" : "FAIL", "\n";
echo "list_kept: ", $got['list'] === range(1, 20) ? "OK" : "FAIL", "\n";

// Independent oracle for the documented derivation: the APCu context tag is
// HMAC-SHA256 over the frame under HMAC-SHA256(key, "phpser:apcu:v1\0").
// phpser_serialize_keyed() runs the same signing code as the APCu hook.
$plain = ['id' => 7, 'name' => 'alice'];
$signed = phpser_serialize_keyed($plain, 'apcu');
$frame = substr($signed, 0, -32);
$subkey = hash_hmac('sha256', "phpser:apcu:v1\0", $key, true);
echo "kat_frame: ", $frame === phpser_serialize($plain) ? "OK" : "FAIL", "\n";
echo "kat_tag: ", hash_equals(hash_hmac('sha256', $frame, $subkey, true), substr($signed, -32)) ? "OK" : "FAIL", "\n";
$forged = phpser_serialize(['user' => 'oracle']);
$forged .= hash_hmac('sha256', $forged, $subkey, true);
echo "oracle_accepted: ", phpser_unserialize_keyed($forged, 'apcu') === ['user' => 'oracle'] ? "OK" : "FAIL", "\n";

// APCu holds frame || tag: the entry is exactly 32 bytes larger than a string
// entry of the plain frame's length, and the same size as one of the signed
// length.
function mem_size(string $key): int {
    foreach (apcu_cache_info()['cache_list'] as $e) {
        if ($e['info'] === $key) return $e['mem_size'];
    }
    return -1;
}
apcu_store('sized_v', $plain);
apcu_store('sized_p', str_repeat('x', strlen($frame)));
apcu_store('sized_s', str_repeat('x', strlen($signed)));
echo "tag_stored: ", mem_size('sized_v') === mem_size('sized_s') && mem_size('sized_v') === mem_size('sized_p') + 32
    ? "OK" : mem_size('sized_v') . " / " . mem_size('sized_p') . " / " . mem_size('sized_s'), "\n";

// Scalars bypass APCu serializers entirely, so they are stored unsigned.
apcu_store('scalar', 'plain string');
var_dump(apcu_fetch('scalar'));
echo "DONE\n";
?>
--EXPECT--
minfo_key: enabled
minfo_apcu: registered, not yet used by APCu in this process
bool(true)
bool(true)
cycle_kept: OK
refs_kept: OK
list_kept: OK
kat_frame: OK
kat_tag: OK
oracle_accepted: OK
tag_stored: OK
string(12) "plain string"
DONE
