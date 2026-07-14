--TEST--
phpser: signed mode (HMAC-SHA256) — round-trip, tamper detection, key isolation
--EXTENSIONS--
phpser
--FILE--
<?php

// --- Round-trip: same key encodes + decodes a value cleanly. ---
$value = ['user' => 'alice', 'id' => 42, 'tags' => ['a', 'b']];
$key = 'shared-secret-key';
$sig = phpser_serialize_signed($value, $key);
$rt = phpser_unserialize_signed($sig, $key);
echo ($rt === $value) ? "roundtrip OK\n" : "roundtrip FAIL\n";

// --- Tag is appended (32 bytes for SHA256). ---
$unsigned = phpser_serialize($value);
echo (strlen($sig) === strlen($unsigned) + 32) ? "tag_size OK\n" : "tag_size FAIL\n";

// --- Wrong key: must throw, must not return the value (no oracle). ---
$caught = false;
try {
    phpser_unserialize_signed($sig, 'different-key');
} catch (Exception $e) {
    $caught = str_contains($e->getMessage(), 'signature');
}
echo $caught ? "wrong_key OK\n" : "wrong_key FAIL\n";

// --- Empty key: an empty key makes HMAC keyless and forgeable, so both
// entry points reject it before doing any work. ---
$enc_threw = false;
try {
    phpser_serialize_signed($value, '');
} catch (Exception $e) {
    $enc_threw = str_contains($e->getMessage(), 'key must not be empty');
}
$sig_real = phpser_serialize_signed($value, $key);
$dec_threw = false;
try {
    phpser_unserialize_signed($sig_real, '');
} catch (Exception $e) {
    $dec_threw = str_contains($e->getMessage(), 'key must not be empty');
}
echo ($enc_threw && $dec_threw) ? "empty_key OK\n" : "empty_key FAIL\n";

// --- Long key (> 64 bytes block size): RFC says hash it first. We do. ---
$long = str_repeat('a', 200);
$siglong = phpser_serialize_signed($value, $long);
$rtlong = phpser_unserialize_signed($siglong, $long);
echo ($rtlong === $value) ? "long_key OK\n" : "long_key FAIL\n";

// --- Binary-safe key (NUL bytes etc). ---
$binkey = "key\x00with\x01nul\x02bytes";
$sigbin = phpser_serialize_signed($value, $binkey);
$rtbin = phpser_unserialize_signed($sigbin, $binkey);
echo ($rtbin === $value) ? "binary_key OK\n" : "binary_key FAIL\n";

// --- Tamper: flip a single bit in the payload body. ---
$tampered = $sig;
$tampered[0] = chr(ord($tampered[0]) ^ 0x01);
$caught = false;
try {
    phpser_unserialize_signed($tampered, $key);
} catch (Exception $e) {
    $caught = true;
}
echo $caught ? "tamper_body OK\n" : "tamper_body FAIL\n";

// --- Tamper: flip a bit in the HMAC tag itself. ---
$tampered = $sig;
$last = strlen($tampered) - 1;
$tampered[$last] = chr(ord($tampered[$last]) ^ 0x80);
$caught = false;
try {
    phpser_unserialize_signed($tampered, $key);
} catch (Exception $e) {
    $caught = true;
}
echo $caught ? "tamper_tag OK\n" : "tamper_tag FAIL\n";

// --- Truncate: payload shorter than 32 bytes can't possibly carry a tag. ---
$caught = false;
try {
    phpser_unserialize_signed("short", $key);
} catch (Exception $e) {
    $caught = str_contains($e->getMessage(), 'too short');
}
echo $caught ? "too_short OK\n" : "too_short FAIL\n";

// --- Truncate by exactly one byte off the end (drops part of the tag). ---
$shortened = substr($sig, 0, -1);
$caught = false;
try {
    phpser_unserialize_signed($shortened, $key);
} catch (Exception $e) {
    $caught = true;
}
echo $caught ? "truncated_one OK\n" : "truncated_one FAIL\n";

// --- Determinism: same key + same value → same signed bytes. ---
$s1 = phpser_serialize_signed($value, $key);
$s2 = phpser_serialize_signed($value, $key);
echo ($s1 === $s2) ? "deterministic OK\n" : "deterministic FAIL\n";

// --- Signed payload through unsigned unserialize: garbage trail (the HMAC
// looks like more data to the unsigned decoder). Should NOT throw, just
// produce some value or NULL — never crash. ---
$rt_unsigned = @phpser_unserialize($sig);
echo "no_crash_unsigned_path OK\n";

// --- allowed_classes still works in signed path. ---
class Approved { public int $n = 7; }
class Rejected { public int $n = 8; }
$sigA = phpser_serialize_signed([new Approved(), new Rejected()], $key);
$rt = phpser_unserialize_signed($sigA, $key, ['allowed_classes' => [Approved::class]]);
$ok = $rt[0] instanceof Approved && $rt[1] instanceof __PHP_Incomplete_Class;
echo $ok ? "signed_allowed_classes OK\n" : "signed_allowed_classes FAIL\n";

// --- Cross-encode unsigned/signed: an unsigned payload through signed
// unserialize fails verification (because there's no valid HMAC suffix). ---
$caught = false;
try {
    phpser_unserialize_signed($unsigned . str_repeat("\x00", 32), $key);
} catch (Exception $e) {
    $caught = true;
}
echo $caught ? "unsigned_in_signed_rejected OK\n" : "unsigned_in_signed_rejected FAIL\n";

// Authentication failure must stop before class lookup or object hooks. The
// first frame names an unloaded class; the second is a valid object frame with
// a deliberately corrupted tag and a visible __wakeup side effect.
$autoloads = 0;
spl_autoload_register(function (string $class) use (&$autoloads): void {
    if ($class === 'SignedAutoloadProbe') $autoloads++;
});
$unknown_body = "\x01\x01\x13SignedAutoloadProbe\x0a\x00\x00";
try {
    phpser_unserialize_signed($unknown_body . str_repeat("\0", 32), $key);
} catch (Exception $e) {
}

class SignedWakeProbe {
    public static int $wakes = 0;
    public function __wakeup(): void { self::$wakes++; }
}
$wake_frame = phpser_serialize_signed(new SignedWakeProbe(), $key);
$wake_frame[strlen($wake_frame) - 1] = chr(ord($wake_frame[strlen($wake_frame) - 1]) ^ 1);
try {
    phpser_unserialize_signed($wake_frame, $key);
} catch (Exception $e) {
}
echo ($autoloads === 0 && SignedWakeProbe::$wakes === 0)
    ? "reject_before_decode OK\n" : "reject_before_decode FAIL\n";

// --- Encoded values can include null and false (which the legacy
// "false=failure" pattern would have ambiguity for — we throw on bad sig
// instead, so the return slot is free). ---
$signull = phpser_serialize_signed(null, $key);
$rtnull = phpser_unserialize_signed($signull, $key);
echo ($rtnull === null) ? "null_value OK\n" : "null_value FAIL\n";

$sigfalse = phpser_serialize_signed(false, $key);
$rtfalse = phpser_unserialize_signed($sigfalse, $key);
echo ($rtfalse === false) ? "false_value OK\n" : "false_value FAIL\n";

// --- Known-answer test: pin one HMAC tag to catch a future drift in the
// underlying SHA256 (e.g. accidental algorithm swap, endianness bug).
// `phpser_serialize("test")` produces a stable byte string in our wire
// format; we sign with a known key and compare the resulting tag hex. ---
$known = phpser_serialize_signed("test", "key");
// The first bytes are the unsigned payload; the last 32 are the HMAC.
$unsigned_test = phpser_serialize("test");
$tag_hex = bin2hex(substr($known, strlen($unsigned_test)));
// Compute the expected tag via PHP's hash_hmac for cross-validation.
$expected_hex = hash_hmac("sha256", $unsigned_test, "key");
echo ($tag_hex === $expected_hex) ? "known_answer OK\n" : "known_answer FAIL ($tag_hex vs $expected_hex)\n";

?>
--EXPECT--
roundtrip OK
tag_size OK
wrong_key OK
empty_key OK
long_key OK
binary_key OK
tamper_body OK
tamper_tag OK
too_short OK
truncated_one OK
deterministic OK
no_crash_unsigned_path OK
signed_allowed_classes OK
unsigned_in_signed_rejected OK
reject_before_decode OK
null_value OK
false_value OK
known_answer OK
