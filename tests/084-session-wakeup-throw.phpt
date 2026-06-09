--TEST--
phpser: session decode whose __wakeup throws fails cleanly without leaking the graph
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
// REGRESSION: when a deferred __wakeup/__unserialize threw, the decoder
// returned -1 with `out` still holding the fully-decoded graph, violating
// its "out is NULL on error" contract. The session decode hook returns
// FAILURE without dtoring its zval, so the whole graph leaked (request-
// bounded, but a debug/ASAN leak report and a latent footgun for any C
// caller trusting the contract). The graph here — a Boom object plus its
// property table — is exactly what leaked. Under a debug or ASAN build the
// harness LEAK detector fails this test if the contract regresses.
session_save_path(sys_get_temp_dir());
session_id('phpserWakeupThrow01');

class Boom {
    public $payload = ['a', 'b', 'c'];
    public function __wakeup(): void {
        throw new RuntimeException('boom in wakeup');
    }
}

// Write a session carrying a Boom object. __wakeup does not fire on encode.
session_start();
$_SESSION['obj'] = new Boom();
$_SESSION['scalar'] = 42;
session_write_close();

// Read it back: decode rebuilds the graph, then __wakeup throws. The decode
// must fail cleanly (exception propagates, no leak, no crash).
$caught = null;
try {
    session_start();
    echo "no_throw FAIL\n";
} catch (\Throwable $e) {
    $caught = $e;
}
echo "wakeup_threw: ", ($caught instanceof RuntimeException
    && $caught->getMessage() === 'boom in wakeup') ? "OK" : "FAIL", "\n";

// Clean up the on-disk session file so reruns start fresh.
@session_destroy();
@unlink(sys_get_temp_dir() . '/sess_phpserWakeupThrow01');

echo "DONE\n";
?>
--EXPECTF--
%AWarning: session_start(): Failed to decode session object. Session has been destroyed%a
wakeup_threw: OK
DONE
