--TEST--
phpser: signed-payload HMAC-SHA256 matches hash_hmac (RFC 4231, lengths 0..300, block-size keys)
--EXTENSIONS--
phpser
--FILE--
<?php
// phpser computes the signed-payload tag with its own SHA-256 compression
// (hardware where available). hash_hmac() is the oracle. Arbitrary data is
// checked through the verify path: a correct tag gets past verification and
// fails only at frame decode; a wrong tag is rejected as a forgery.
function verifies(string $data, string $tag, string $key): bool {
    try {
        phpser_unserialize_signed($data . $tag, $key);
        return true;
    } catch (Exception $e) {
        if ($e->getMessage() === 'phpser: signature verification failed') return false;
        if ($e->getMessage() === 'phpser: signed payload failed to decode') return true;
        throw $e;
    }
}

$rfc = [
    [str_repeat("\x0b", 20), "Hi There",
     'b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7'],
    ["Jefe", "what do ya want for nothing?",
     '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843'],
    [str_repeat("\xaa", 20), str_repeat("\xdd", 50),
     '773ea91e36800e46854db8ebd09181a72959098b3ef8c122d9635514ced565fe'],
    [hex2bin('0102030405060708090a0b0c0d0e0f10111213141516171819'), str_repeat("\xcd", 50),
     '82558a389a443c0ea4cc819899f2083a85f0faa3e578f8077a2e3ff46729665b'],
    [str_repeat("\x0c", 20), "Test With Truncation",
     'a3b6167473100ee06e0c796c2955552bfa6f7c0a6a8aef8b93f860aab0cd20c5'],
    [str_repeat("\xaa", 131), "Test Using Larger Than Block-Size Key - Hash Key First",
     '60e431591ee0b67f0d8a26aacbf5b77f8e0bc6213728c5140546040f0ee37f54'],
    [str_repeat("\xaa", 131), "This is a test using a larger than block-size key and a larger than block-size data. The key needs to be hashed before being used by the HMAC algorithm.",
     '9b09ffa71b942fcb27635fbcd5b0e944bfdc63644f0713938a7f51535c3a35e2'],
];
foreach ($rfc as $i => [$key, $data, $want]) {
    $tag = hex2bin($want);
    $ok = hash_hmac('sha256', $data, $key) === $want
        && verifies($data, $tag, $key)
        && !verifies($data, $tag ^ str_pad("\x01", 32, "\0"), $key);
    echo "rfc4231 case ", $i + 1, ": ", $ok ? "OK" : "FAIL", "\n";
}

// Every data length 0..300 crosses the one/two padding-block boundary
// (55/56 mod 64) and several full-block counts; each key length class
// exercises the raw, block-sized, and hashed-key branches.
$keys = ["k", str_repeat("K", 32), str_repeat("\x5a", 63), str_repeat("\xa5", 64),
         str_repeat("\x3c", 65), random_bytes(200)];
$buf = random_bytes(300);
$bad = 0;
foreach ($keys as $key) {
    for ($n = 0; $n <= 300; $n++) {
        $data = substr($buf, 0, $n);
        $tag = hash_hmac('sha256', $data, $key, true);
        if (!verifies($data, $tag, $key)) { $bad++; echo "mismatch key_len=", strlen($key), " n=$n\n"; }
        $forged = $tag;
        $forged[$n % 32] = chr(ord($forged[$n % 32]) ^ 0x80);
        if (verifies($data, $forged, $key)) { $bad++; echo "forgery accepted key_len=", strlen($key), " n=$n\n"; }
    }
}
echo "lengths 0..300 x ", count($keys), " keys: ", $bad === 0 ? "OK" : "FAIL", "\n";

// The signing side must emit exactly hash_hmac over the frame.
$bad = 0;
foreach ($keys as $key) {
    for ($n = 0; $n <= 300; $n += 7) {
        foreach ([substr($buf, 0, $n), range(0, $n), ['s' => str_repeat('x', $n)]] as $v) {
            $s = phpser_serialize_signed($v, $key);
            $frame = substr($s, 0, -32);
            if (substr($s, -32) !== hash_hmac('sha256', $frame, $key, true)) $bad++;
            if (phpser_unserialize_signed($s, $key) !== $v) $bad++;
        }
    }
}
echo "sign path: ", $bad === 0 ? "OK" : "FAIL", "\n";

// Large inputs: many full blocks through the multi-block loop, plus
// off-by-one lengths around a block multiple.
$bad = 0;
$big = random_bytes(1 << 20);
foreach ([4095, 4096, 4097, 65536 + 55, 65536 + 56, 1 << 20] as $n) {
    $data = substr($big, 0, $n);
    foreach ([$keys[1], $keys[5]] as $key) {
        if (!verifies($data, hash_hmac('sha256', $data, $key, true), $key)) { $bad++; echo "big mismatch n=$n\n"; }
    }
}
$v = ['blob' => $big, 'list' => range(1, 5000)];
$s = phpser_serialize_signed($v, $keys[1]);
if (substr($s, -32) !== hash_hmac('sha256', substr($s, 0, -32), $keys[1], true)) $bad++;
if (phpser_unserialize_signed($s, $keys[1]) !== $v) $bad++;
echo "large inputs: ", $bad === 0 ? "OK" : "FAIL", "\n";
?>
--EXPECT--
rfc4231 case 1: OK
rfc4231 case 2: OK
rfc4231 case 3: OK
rfc4231 case 4: OK
rfc4231 case 5: OK
rfc4231 case 6: OK
rfc4231 case 7: OK
lengths 0..300 x 6 keys: OK
sign path: OK
large inputs: OK
