--TEST--
phpser: phpser.session_key signs session frames and rejects tampered, unsigned, and foreign-ID blobs
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
session_save_path(sys_get_temp_dir());

class Gadget {
    public function __wakeup() { echo "GADGET WOKE\n"; }
}

$msgs = [];
set_error_handler(function ($no, $str) use (&$msgs) { $msgs[] = $str; return true; });

function open_as(string $id): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_write_close();
    }
    session_id($id);
    session_start();
}

function rejected(string $label, string $payload, string $why = 'failed HMAC verification'): void {
    global $msgs;
    $msgs = [];
    $_SESSION = [];
    $r = session_decode($payload);
    $hmac = (bool) preg_grep('/' . preg_quote($why, '/') . '/', $msgs);
    echo "$label: ", ($r === false && $hmac && session_status() !== PHP_SESSION_ACTIVE)
        ? "rejected" : "ACCEPTED", "\n";
}

$sidA = 'phpsersignedtestA' . getmypid();
$sidB = 'phpsersignedtestB' . getmypid();

open_as($sidA);
$_SESSION = ['user' => 'alice', 'role' => 'user'];
$signed = session_encode();
echo "tag_appended: ", strlen($signed) > 32 ? "yes" : "no", "\n";

$_SESSION = [];
echo "roundtrip: ", (session_decode($signed) !== false && $_SESSION === ['user' => 'alice', 'role' => 'user'])
    ? "OK" : "FAIL", "\n";

$flip = $signed; $flip[3] = chr(ord($flip[3]) ^ 1);
rejected('frame_bit_flip', $flip);

open_as($sidA);
$flip = $signed; $flip[strlen($flip) - 1] = chr(ord($flip[strlen($flip) - 1]) ^ 1);
rejected('tag_bit_flip', $flip);

open_as($sidA);
rejected('truncated_to_tag', substr($signed, -32));

open_as($sidA);
rejected('dropped_tag', substr($signed, 0, -32));

open_as($sidA);
rejected('suffixed', $signed . "\x00");

open_as($sidA);
rejected('unsigned_forgery', phpser_serialize(['user' => 'alice', 'role' => 'admin']));

open_as($sidA);
rejected('unsigned_gadget', phpser_serialize(['g' => new Gadget]));

open_as($sidA);
rejected('signed_cache_payload', phpser_serialize_signed(['user' => 'alice', 'role' => 'admin'],
    'test-session-key-0123456789abcdef'));

// The same bytes are valid under A's ID only.
open_as($sidB);
rejected('other_session_id', $signed);

open_as($sidA);
$_SESSION = [];
echo "own_id_still_valid: ", (session_decode($signed) !== false && ($_SESSION['user'] ?? null) === 'alice')
    ? "OK" : "FAIL", "\n";

$_SESSION = ['stale' => 1];
echo "empty_is_new_session: ", (session_decode('') !== false && $_SESSION === []) ? "OK" : "FAIL", "\n";

// Over-depth encode still persists the unsigned tombstone; a signed read rejects it.
$deep = 'leaf';
for ($i = 0; $i < 1000; $i++) $deep = [$deep];
$_SESSION = ['deep' => $deep];
$tomb = session_encode();
echo "overdepth_tombstone: ", $tomb === 'phpser:session-not-serialized' ? "yes" : "no", "\n";
rejected('tombstone_read', $tomb, 'was not saved by the previous request');

echo "ini_set_denied: ", ini_set('phpser.session_key', 'attacker') === false
    && ini_get('phpser.session_key') === 'test-session-key-0123456789abcdef' ? "yes" : "no", "\n";

restore_error_handler();
echo "DONE\n";
?>
--CLEAN--
<?php
foreach (glob(sys_get_temp_dir() . '/sess_phpsersignedtest*') as $f) @unlink($f);
?>
--EXPECT--
tag_appended: yes
roundtrip: OK
frame_bit_flip: rejected
tag_bit_flip: rejected
truncated_to_tag: rejected
dropped_tag: rejected
suffixed: rejected
unsigned_forgery: rejected
unsigned_gadget: rejected
signed_cache_payload: rejected
other_session_id: rejected
own_id_still_valid: OK
empty_is_new_session: OK
overdepth_tombstone: yes
tombstone_read: rejected
ini_set_denied: yes
DONE
