--TEST--
phpser: session handler error arms — encode hook throws, non-array root decodes to an empty session
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
session_save_path(sys_get_temp_dir());
session_start();

// (1) A serialization hook that throws during session encode. The phpser encode
// handler flags PHPSER_ENC_EXCEPTION and emits this specific E_WARNING — asserted
// below (not suppressed) so a regression of THIS arm is observable: the
// __serialize exception propagates whether phpser returns NULL or a partial
// frame, so the warning is the only thing that pins the arm. The exception then
// propagates out of session_encode(); no partial graph is written.
class BoomEnc {
    public $x = 1;
    public function __serialize(): array { throw new Exception("boom-encode"); }
}
$_SESSION = ['b' => new BoomEnc()];
try {
    session_encode();
    echo "encode_hook_throw: no-throw FAIL\n";
} catch (\Throwable $e) {
    echo "encode_hook_throw: ", $e->getMessage(), "\n";
}
$_SESSION = [];   // clear so the request-shutdown auto-save doesn't re-encode it

// (2) A payload that decodes to a non-array root must become an empty session,
// not a decode FAILURE that makes the engine destroy the session.
$scalar_payload = phpser_serialize(42);   // decodes to int(42) — non-array root
$_SESSION = ['stale' => 1];
$r = session_decode($scalar_payload);
echo "nonarray_root_decode: ",
    ($r !== false && $_SESSION === []) ? "OK" : "FAIL", "\n";

$_SESSION = [];
session_write_close();
echo "DONE\n";
?>
--EXPECTF--
Warning: session_encode(): phpser: $_SESSION not serialized — a serialization hook threw in %s on line %d
encode_hook_throw: boom-encode
nonarray_root_decode: OK
DONE
