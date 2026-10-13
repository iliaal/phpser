--TEST--
phpser: signed session tag matches the documented KDF; key changes, strict mode, reset, and hook throws across requests
--EXTENSIONS--
phpser
session
--SKIPIF--
<?php
if (@ini_set('session.serialize_handler', 'phpser') === false) {
    die('skip phpser built without session support (HAVE_PHP_SESSION undefined)');
}
if (!function_exists('shell_exec')) die('skip shell_exec disabled');
?>
--INI--
session.serialize_handler=phpser
session.save_handler=files
session.use_cookies=0
session.use_strict_mode=0
session.cache_limiter=
phpser.session_key=kat-key-0123456789abcdef0123456789
--FILE--
<?php
ob_start();
$key = 'kat-key-0123456789abcdef0123456789';
$dir = sys_get_temp_dir() . '/phpser-216-' . md5(__DIR__);
@mkdir($dir);
session_save_path($dir);

// Independent oracle: the tag is HMAC-SHA256 over the frame under
// HMAC-SHA256(key, "phpser:session:v1\0" . session_id).
session_id('katsession1');
session_start();
$_SESSION = ['user' => 'alice', 'n' => [1, 2, 3]];
$enc = session_encode();
$frame = substr($enc, 0, -32);
$subkey = hash_hmac('sha256', "phpser:session:v1\0" . session_id(), $key, true);
echo "kat_tag: ", hash_equals(hash_hmac('sha256', $frame, $subkey, true), substr($enc, -32)) ? "OK" : "FAIL", "\n";
echo "kat_frame: ", phpser_unserialize($frame) === $_SESSION ? "OK" : "FAIL", "\n";

// A blob forged with the oracle under the right key is accepted: the
// implementation and the documented derivation are the same function.
$forged_frame = phpser_serialize(['user' => 'oracle']);
$forged = $forged_frame . hash_hmac('sha256', $forged_frame, $subkey, true);
$_SESSION = [];
echo "oracle_blob_accepted: ", (session_decode($forged) !== false && $_SESSION === ['user' => 'oracle'])
    ? "OK" : "FAIL", "\n";

// session_reset() re-reads and re-verifies the stored blob.
$_SESSION = ['user' => 'alice'];
session_write_close();
session_id('katsession1');
session_start();
$_SESSION['user'] = 'mutated';
session_reset();
echo "reset_restores: ", ($_SESSION['user'] ?? null) === 'alice' ? "OK" : "FAIL", "\n";
session_write_close();

// Separate requests: key change, key removal, strict mode, hook throw.
function req(string $code, array $ini): string {
    $php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
    $extra = getenv('TEST_PHP_EXTRA_ARGS') ?: '';
    $dir = sys_get_temp_dir() . '/phpser-216-' . md5(__DIR__);
    $ini += [
        'session.serialize_handler' => 'phpser', 'session.save_handler' => 'files',
        'session.use_cookies' => '0', 'session.cache_limiter' => '', 'display_errors' => '1',
    ];
    // Child code goes through a file: Windows escapeshellarg() strips double
    // quotes, which would mangle code passed with -r. The save path is set in
    // code because an unquoted -d value with `~` (Windows 8.3 short temp
    // names) is an INI syntax error.
    $script = "$dir/child-" . md5($code) . '.php';
    file_put_contents($script, "<?php\nsession_save_path(" . var_export($dir, true) . ");\n" . $code);
    $cmd = escapeshellarg($php) . " $extra";
    foreach ($ini as $k => $v) $cmd .= ' -d ' . escapeshellarg("$k=$v");
    $cmd .= ' ' . escapeshellarg($script) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    unlink($script);
    return $out;
}
// TEST_PHP_EXTRA_ARGS carries this test's --INI-- section, key included,
// so the keyless and other-key requests override it explicitly.
$k1 = ['phpser.session_key' => $key];
$k2 = ['phpser.session_key' => 'another-key-0123456789abcdef012345'];
$write = 'session_id("xreq1"); session_start(); $_SESSION = ["v" => 7]; session_write_close(); echo "w";';
$read = 'session_id("xreq1"); session_start(); echo json_encode($_SESSION);';
echo "cross_write: ", req($write, $k1), "\n";
echo "cross_same_key: ", req($read, $k1), "\n";
echo "cross_other_key: ", str_contains(req($read, $k2), 'failed HMAC verification') ? "rejected" : "ACCEPTED", "\n";
req($write, $k1);
echo "cross_no_key: ", str_contains(req($read, ['phpser.session_key' => '']), 'Failed to decode session object') ? "rejected" : "ACCEPTED", "\n";

// Strict mode replaces an unknown ID before the read; the new ID round-trips.
$strict = $k1 + ['session.use_strict_mode' => '1'];
$sid = trim(req('session_id("neverissued1"); session_start(); $_SESSION = ["s" => 1]; echo session_id();', $strict));
echo "strict_new_id: ", $sid !== 'neverissued1' && strlen($sid) > 0 ? "OK" : "FAIL", "\n";
echo "strict_roundtrip: ", req('session_id("' . $sid . '"); session_start(); echo json_encode($_SESSION);', $strict), "\n";

// A hook that throws during the save persists no signed data. PHP 8.6+
// skips the write, so the previous session survives; earlier versions write
// an empty session, which reads back as new rather than as a forgery.
$boom = 'class Boom { function __serialize(): array { throw new Exception("x"); } }
session_id("hookthrow1"); session_start(); $_SESSION = ["b" => new Boom];
try { session_write_close(); echo "NO-THROW"; } catch (Throwable $e) { echo "CAUGHT:", $e->getMessage(); }';
req('session_id("hookthrow1"); session_start(); $_SESSION = ["keep" => 1];', $k1);
$out = req($boom, $k1);
echo "hook_throw: ", str_contains($out, 'a serialization hook threw') && str_contains($out, 'CAUGHT:x') ? "threw" : $out, "\n";
$after = req('session_id("hookthrow1"); session_start(); echo json_encode($_SESSION);', $k1);
echo "hook_throw_next_read: ", $after === (PHP_VERSION_ID >= 80600 ? '{"keep":1}' : '[]') ? "OK" : $after, "\n";
// A key with another serialize_handler signs nothing; phpinfo() says so.
$minfo = req('(new ReflectionExtension("phpser"))->info();', $k1 + ['session.serialize_handler' => 'php']);
echo "minfo_other_handler: ", preg_match('/session HMAC signing.*inactive/', $minfo) ? "inactive" : $minfo, "\n";
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-216-' . md5(__DIR__);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
kat_tag: OK
kat_frame: OK
oracle_blob_accepted: OK
reset_restores: OK
cross_write: w
cross_same_key: {"v":7}
cross_other_key: rejected
cross_no_key: rejected
strict_new_id: OK
strict_roundtrip: {"s":1}
hook_throw: threw
hook_throw_next_read: OK
minfo_other_handler: inactive
DONE
