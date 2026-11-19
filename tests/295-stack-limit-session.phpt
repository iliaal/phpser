--TEST--
phpser: session encode that exhausts the C stack, directly or in a nested hook, warns and stores the failure marker
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) die("skip zend.max_allowed_stack_size needs PHP 8.3+");
if (@ini_set('session.serialize_handler', 'phpser') === false) {
    die('skip phpser built without session support (HAVE_PHP_SESSION undefined)');
}
// Engines built without ZEND_CHECK_STACK_LIMIT never raise the stack Error;
// sanitizer builds reserve more than 128K, leaving no usable stack at all.
$probe = 'function f($x) { return array_map("f", [$x]); } try { f(1); } catch (Error $e) { echo str_contains($e->getMessage(), "Maximum call stack size") ? "ok" : "other"; }';
$out = shell_exec(escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' -n -d zend.max_allowed_stack_size=128K -r ' . escapeshellarg($probe) . ' 2>&1');
if ($out !== 'ok') die("skip no C-stack limit Error at zend.max_allowed_stack_size=128K on this build");
?>
--INI--
zend.max_allowed_stack_size=128K
session.serialize_handler=phpser
session.save_handler=files
session.use_cookies=0
session.cache_limiter=
--FILE--
<?php
session_save_path(sys_get_temp_dir());
session_start();
// The handler has no frame to throw into at request-end auto-save, so the
// stack error degrades to a warning like the depth cap does.
$a = 42;
for ($i = 0; $i < 511; $i++) { $a = [$a]; }
$_SESSION = ['deep' => $a];
var_dump(session_encode());

// A nested phpser_serialize() from __serialize() reports the low stack as the
// engine's Error; the handler stores the marker for it too.
class Hop {
    public function __construct(public $next) {}
    public function __serialize(): array {
        $a = $this->next;
        for ($i = 0; $i < 100; $i++) { $a = [$a]; }
        return ['s' => phpser_serialize($a)];
    }
    public function __unserialize(array $data): void {}
}
$leaf = 1;
for ($h = 0; $h < 20; $h++) { $leaf = new Hop($leaf); }
$_SESSION = ['hops' => $leaf];
var_dump(session_encode());

// An Error the application throws with the same message is not a stack
// failure and propagates unchanged.
class Fake {
    public function __serialize(): array {
        throw new Error("Maximum call stack size of 1 bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?");
    }
    public function __unserialize(array $data): void {}
}
$_SESSION = ['fake' => new Fake];
try {
    var_dump(session_encode());
} catch (Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECTF--
Warning: session_encode(): phpser: $_SESSION not serialized — nesting exhausted the C stack in %s on line %d
string(29) "phpser:session-not-serialized"

Warning: session_encode(): phpser: $_SESSION not serialized — nesting exhausted the C stack in %s on line %d
string(29) "phpser:session-not-serialized"

Warning: session_encode(): phpser: $_SESSION not serialized — an exception was thrown during serialization in %s on line %d
Error: Maximum call stack size of 1 bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?
DONE
