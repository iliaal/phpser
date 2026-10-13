--TEST--
phpser: signed sessions persist through the files handler, reject on-disk tampering, and survive session_regenerate_id()
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
session.use_strict_mode=0
session.cache_limiter=
phpser.session_key=test-session-key-0123456789abcdef
--FILE--
<?php
ob_start();
$dir = sys_get_temp_dir() . '/phpser-signed-files-215';
@mkdir($dir);
session_save_path($dir);

$msgs = [];
set_error_handler(function ($no, $str) use (&$msgs) { $msgs[] = $str; return true; });

function read_as(string $id): array {
    session_id($id);
    session_start();
    $data = $_SESSION;
    session_write_close();
    return $data;
}

session_id('alice');
session_start();
$_SESSION = ['user' => 'alice', 'n' => 1];
session_write_close();
echo "persisted: ", read_as('alice') === ['user' => 'alice', 'n' => 1] ? "OK" : "FAIL", "\n";

session_id('root');
session_start();
$_SESSION = ['user' => 'root', 'role' => 'admin'];
session_write_close();

// A store writer copies root's valid blob over alice's file.
copy("$dir/sess_root", "$dir/sess_alice");
$msgs = [];
$got = read_as('alice');
echo "copied_blob: ", ($got === [] && preg_grep('/failed HMAC verification/', $msgs)) ? "rejected" : "ACCEPTED", "\n";

// Corrupt one byte of root's file in place.
$raw = file_get_contents("$dir/sess_root");
$raw[2] = chr(ord($raw[2]) ^ 0x20);
file_put_contents("$dir/sess_root", $raw);
$msgs = [];
$got = read_as('root');
echo "on_disk_flip: ", ($got === [] && preg_grep('/failed HMAC verification/', $msgs)) ? "rejected" : "ACCEPTED", "\n";

$msgs = [];
// regenerate_id(false) keeps the old file readable under the old ID, and the
// new ID's file is signed for the new ID.
session_id('bob1');
session_start();
$_SESSION = ['user' => 'bob'];
echo "", session_regenerate_id(false) ? "" : "regen(false) FAILED\n";
$new = session_id();
echo "", $new === "bob1" || $new === "carol1" ? "ID UNCHANGED\n" : "";
session_write_close();
echo "regen_keep_old: ", read_as('bob1') === ['user' => 'bob'] ? "OK" : "FAIL", "\n";
echo "regen_new_id: ", read_as($new) === ['user' => 'bob'] ? "OK" : "FAIL", "\n";

session_id('carol1');
session_start();
$_SESSION = ['user' => 'carol'];
echo "", session_regenerate_id(true) ? "" : "regen(true) FAILED\n";
$new = session_id();
echo "", $new === "bob1" || $new === "carol1" ? "ID UNCHANGED\n" : "";
session_write_close();
echo "regen_delete_old: ", (!file_exists("$dir/sess_carol1") && read_as($new) === ['user' => 'carol'])
    ? "OK" : "FAIL", "\n";

echo "no_warnings_on_valid_paths: ", preg_grep('/HMAC/', $msgs) ? "FAIL" : "OK", "\n";
restore_error_handler();
echo "DONE\n";
?>
--CLEAN--
<?php
$dir = sys_get_temp_dir() . '/phpser-signed-files-215';
foreach (glob("$dir/sess_*") as $f) @unlink($f);
@rmdir($dir);
?>
--EXPECT--
persisted: OK
copied_blob: rejected
on_disk_flip: rejected
regen_keep_old: OK
regen_new_id: OK
regen_delete_old: OK
no_warnings_on_valid_paths: OK
DONE
