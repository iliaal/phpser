--TEST--
phpser: a throwing lazy-object initializer persists nothing to the session store
--DESCRIPTION--
The CR-003 guard converts a pending exception from zend_get_properties_for
into e->failed. tests/099 cannot observe it: on the userland path the pending
exception wins over the return value either way. The session handler is where
it matters — before the guard, phpser handed session.c a complete frame with
the lazy object encoded as an empty object, and session.c wrote it.
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) die("skip lazy objects (newLazyGhost) require PHP 8.4+");
if (@ini_set('session.serialize_handler', 'phpser') === false) {
    die('skip phpser built without session support (HAVE_PHP_SESSION undefined)');
}
?>
--INI--
session.serialize_handler=phpser
session.save_handler=files
session.use_cookies=0
session.cache_limiter=
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpser124_' . getmypid();
@mkdir($dir);
session_save_path($dir);
session_id('probe124');
session_start();

class LazyDto124 { public int $x = 0; public string $y = "payload"; }

$ghost = (new ReflectionClass(LazyDto124::class))
    ->newLazyGhost(function ($o) { throw new RuntimeException("init boom"); });

$_SESSION['before'] = 'AAA';
$_SESSION['obj']    = $ghost;
$_SESSION['after']  = 'BBB';

try {
    session_write_close();
    echo "no throw\n";
} catch (\Throwable $e) {
    echo "threw: ", $e->getMessage(), "\n";
}

// Nothing may reach the store: a frame here would silently drop the object's
// state and read back as an empty object on the next request.
$file = $dir . '/sess_probe124';
echo file_exists($file) ? "bytes=" . filesize($file) . "\n" : "no file\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser124_' . getmypid();
@unlink($dir . '/sess_probe124');
@rmdir($dir);
?>
--EXPECTF--
Warning: session_write_close(): phpser: $_SESSION not serialized — a serialization hook threw in %s on line %d
threw: init boom
bytes=0
