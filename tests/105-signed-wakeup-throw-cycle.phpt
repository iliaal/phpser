--TEST--
phpser: signed decode of a cyclic graph whose __wakeup throws — fails cleanly, no leak of the pinned graph
--EXTENSIONS--
phpser
--FILE--
<?php
// __wakeup is deferred to the end of the decode pass. On the signed (trusted)
// path every object is pinned in the id-table for the pass (CR-001). When a
// deferred __wakeup throws mid-cycle, the decode must abort and decode_destroy
// must release the whole pinned graph — no leak, no double-free, no UAF — while
// the exception propagates (phpser_unserialize_signed throws on failure). Under
// valgrind/ASan a teardown imbalance here would surface as lost/invalid blocks.
#[\AllowDynamicProperties]
class Wk {
    public $peer;
    public $n = 1;
    public function __wakeup() { throw new Exception("boom from wakeup"); }
}
$key = str_repeat("k", 32);

$a = new Wk(); $b = new Wk();
$a->peer = $b; $b->peer = $a;   // cycle: a <-> b
$signed = phpser_serialize_signed([$a, $b], $key);

try {
    phpser_unserialize_signed($signed, $key);
    echo "no-throw FAIL\n";
} catch (\Throwable $e) {
    echo "threw: " . $e->getMessage() . "\n";
}
echo "survived\n";
?>
--EXPECT--
threw: boom from wakeup
survived
