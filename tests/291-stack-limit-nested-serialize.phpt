--TEST--
phpser: __serialize calling phpser_serialize stops at the C-stack limit instead of crashing
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) die("skip zend.max_allowed_stack_size needs PHP 8.3+");
// Engines built without ZEND_CHECK_STACK_LIMIT never raise the stack Error.
$probe = 'function f($x) { return array_map("f", [$x]); } try { f(1); } catch (Error $e) { echo str_contains($e->getMessage(), "Maximum call stack size") ? "ok" : "other"; }';
$out = shell_exec(escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' -n -r ' . escapeshellarg($probe) . ' 2>&1');
if ($out !== 'ok') die("skip no C-stack limit Error on this build");
?>
--FILE--
<?php
// Each nested phpser_serialize() starts a fresh encode context, so the
// MAX_DEPTH cap never fires; 400 hops outgrow an 8 MiB stack. The engine's own
// check before each __serialize() frame catches some depths but not others,
// so several are tried. The nesting is built inside __serialize so
// script-end destruction stays shallow.
class Hop {
    public function __construct(public $next, public int $depth) {}
    public function __serialize(): array {
        $a = $this->next;
        for ($i = 0; $i < $this->depth; $i++) { $a = [$a]; }
        return ['s' => phpser_serialize($a)];
    }
    public function __unserialize(array $data): void {}
}
foreach ([440, 460, 505, 511] as $depth) {
    $leaf = 1;
    for ($h = 0; $h < 400; $h++) { $leaf = new Hop($leaf, $depth); }
    try {
        phpser_serialize($leaf);
        echo "$depth: no error\n";
    } catch (Error $e) {
        echo "$depth: ", str_contains($e->getMessage(), "Maximum call stack size") ? "stack error" : $e->getMessage(), "\n";
    }
}
var_dump(strlen(phpser_serialize(new Hop(new Hop(1, 3), 3))) > 0);
?>
--EXPECT--
440: stack error
460: stack error
505: stack error
511: stack error
bool(true)
