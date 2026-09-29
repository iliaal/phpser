--TEST--
phpser: cycles orphaned by decode hooks or held through references stay collectable
--EXTENSIONS--
phpser
--INI--
zend.enable_gc=1
--FILE--
<?php
// Hooks run after the graph is stitched but before the id-table pins drop. A
// hook that detaches a cycle and runs the collector clears the only root the
// detach recorded, so the pin release must record it again.
$destroyed = [];
class A { public $b; public function __destruct() { $GLOBALS['destroyed'][] = 'A'; } }
class B { public $a; public function __destruct() { $GLOBALS['destroyed'][] = 'B'; } }

class W {
    public $c;
    public function __wakeup(): void { $this->c = null; gc_collect_cycles(); }
}
class U {
    public $c;
    public function __serialize(): array { return ['c' => $this->c]; }
    public function __unserialize(array $d): void {
        $this->c = $d['c'];
        $this->c = null;
        gc_collect_cycles();
    }
}

spl_autoload_register(function ($c) {
    if ($c === 'Trig') gc_collect_cycles();
});

function cyc(): A {
    $a = new A();
    $a->b = new B();
    $a->b->a = $a;
    return $a;
}

function check(string $name, callable $decode): void {
    $GLOBALS['destroyed'] = [];
    $r = $decode();
    $n = gc_collect_cycles();
    $log = $GLOBALS['destroyed'];
    sort($log);
    printf("%s: %s, collected %d, destroyed [%s]\n",
        $name, gettype($r), $n, implode(',', $log));
    unset($r);
    gc_collect_cycles();
}

$w = new W();
$w->c = cyc();
$pw = phpser_serialize($w);
$u = new U();
$u->c = cyc();
$pu = phpser_serialize($u);
unset($w, $u);
gc_collect_cycles();

check('wakeup_detach', fn() => phpser_unserialize($pw));
check('unserialize_detach', fn() => phpser_unserialize($pu));
$key = str_repeat("h", 32);
$sw = $pw . hash_hmac('sha256', $pw, $key, true);
check('signed_wakeup_detach', fn() => phpser_unserialize_signed($sw, $key));

function vi(int $n): string {
    $s = '';
    while ($n >= 0x80) { $s .= chr(($n & 0x7f) | 0x80); $n >>= 7; }
    return $s . chr($n);
}
function frame(array $dict, string $body): string {
    $s = "\x01" . vi(count($dict));
    foreach ($dict as $e) $s .= vi(strlen($e)) . $e;
    return $s . $body;
}

// Duplicate key drops a reference inside the cycle ref -> A -> B -> ref
// (ref id 0, A id 1, B id 2).
$dict = ['p', 'A', 'b', 'B', 'a', 'z', 'Trig'];
$refdup = frame($dict, "\x06\x03"
    . "\x01\x00" . "\x11" . "\x0a\x01\x01\x02" . "\x0a\x03\x01\x04" . "\x10\x00"
    . "\x01\x00" . "\x03\x02"
    . "\x01\x05" . "\x0a\x06\x00");
check('ref_dup', fn() => phpser_unserialize($refdup));

// A reference kept in the result is shared and collectable once dropped.
$x = cyc();
$v = ['r1' => &$x, 'r2' => &$x];
$pr = phpser_serialize($v);
unset($x, $v);
gc_collect_cycles();
$GLOBALS['destroyed'] = [];
$r = phpser_unserialize($pr);
$r['r1'] = cyc();
echo "ref kept shared: ", var_export($r['r2'] === $r['r1'], true), "\n";
echo "old cycle collected: ", gc_collect_cycles(), "\n";
unset($r);
echo "result collected: ", gc_collect_cycles(), "\n";

// Top-level TAG_NEW_REF around an array that references itself through it.
$top = frame([], "\x11\x07\x01\x10\x00");
gc_collect_cycles();
$before = gc_status()['roots'];
$t = phpser_unserialize($top);
echo "top type: ", gettype($t), ", inner is array: ",
    var_export(is_array($t[0]) && is_array($t[0][0]), true), "\n";
echo "top roots<10: ", var_export(gc_status()['roots'] - $before < 10, true), "\n";
unset($t);
echo "top collected: ", var_export(gc_collect_cycles() > 0, true), "\n";
echo "roots left: ", gc_status()['roots'], "\n";
?>
--EXPECT--
wakeup_detach: object, collected 2, destroyed [A,B]
unserialize_detach: object, collected 2, destroyed [A,B]
signed_wakeup_detach: object, collected 2, destroyed [A,B]
ref_dup: array, collected 2, destroyed [A,B]
ref kept shared: true
old cycle collected: 2
result collected: 2
top type: array, inner is array: true
top roots<10: true
top collected: true
roots left: 0
