--TEST--
phpser: destructor exceptions during encoder cleanup never persist a completed session frame
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
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
class CleanupBomb {
    public function __destruct() {
        throw new RuntimeException('cleanup-boom');
    }
}

class CleanupProducer {
    public function __serialize(): array {
        return ['bomb' => new CleanupBomb(), 'new' => 42];
    }
}

$dir = sys_get_temp_dir() . '/phpser-cleanup-' . getmypid();
mkdir($dir);
session_save_path($dir);
session_id('cleanup-test');
session_start();
$_SESSION = ['old' => 1];
session_write_close();

session_id('cleanup-test');
session_start();
$_SESSION = ['candidate' => new CleanupProducer()];
try {
    session_write_close();
    echo "exception FAIL\n";
} catch (RuntimeException $e) {
    echo ($e->getMessage() === 'cleanup-boom') ? "exception OK\n" : "exception FAIL\n";
}

$stored = file_get_contents($dir . '/sess_cleanup-test');
echo $stored === '' ? "partial_commit OK\n" : "partial_commit FAIL\n";

@unlink($dir . '/sess_cleanup-test');
@rmdir($dir);
?>
--EXPECTF--
Warning: session_write_close(): phpser: $_SESSION not serialized — a serialization hook threw in %s on line %d
exception OK
partial_commit OK
