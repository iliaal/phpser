--TEST--
phpser: HMAC key-midstate cache never reuses a stale key
--EXTENSIONS--
phpser
--FILE--
<?php
// The signer caches the inner/outer midstates of the last key per thread.
// Every transition below changes the key between calls; a stale cache
// would sign with, or accept a tag from, the previous key.
function tag_ok(string $s, string $key): bool {
    return substr($s, -32) === hash_hmac('sha256', substr($s, 0, -32), $key, true);
}
function accepts(string $s, string $key): bool {
    try { phpser_unserialize_signed($s, $key); return true; }
    catch (Exception $e) {
        if ($e->getMessage() === 'phpser: signature verification failed') return false;
        throw $e;
    }
}

$v = ['id' => 7, 'name' => 'cache'];
$a = str_repeat("A", 32);
$b = str_repeat("A", 31) . "B";           // differs only in the last byte
$long1 = str_repeat("L", 100);
$long2 = str_repeat("L", 99) . "M";       // over-long keys differing at the end
$pairs = [[$a, $b], [$b, $a], [$long1, $long2], [$long2, $long1], [$a, $long1],
          [$long1, $a], ["x", "x\x01"], [str_repeat("\xff", 64), str_repeat("\xff", 63) . "\xfe"]];
foreach ($pairs as $i => [$k1, $k2]) {
    $s1 = phpser_serialize_signed($v, $k1);
    $s2 = phpser_serialize_signed($v, $k2);
    $ok = tag_ok($s1, $k1) && tag_ok($s2, $k2) && $s1 !== $s2
        && accepts($s1, $k1) && !accepts($s1, $k2)
        && accepts($s2, $k2) && !accepts($s2, $k1)
        && !accepts($s2, $k1) && accepts($s1, $k1);
    echo "transition $i: ", $ok ? "OK" : "FAIL", "\n";
}

// HMAC zero-pads short keys to the block size, so "k" and "k\0" are the same
// key, and replaces an over-long key with its SHA-256 digest. The cache keys
// on that normalized block; hash_hmac confirms both equivalences.
$s = phpser_serialize_signed($v, "k");
echo "zero-pad equivalence: ", (accepts($s, "k\0") && tag_ok($s, "k\0")) ? "OK" : "FAIL", "\n";
$k65 = str_repeat("q", 65);
$s = phpser_serialize_signed($v, $k65);
$digest = hash('sha256', $k65, true);
echo "hashed key equivalence: ", (accepts($s, $digest) && tag_ok($s, $digest)) ? "OK" : "FAIL", "\n";
echo "64-byte key is not hashed: ", !accepts(phpser_serialize_signed($v, str_repeat("q", 64)), hash('sha256', str_repeat("q", 64), true)) ? "OK" : "FAIL", "\n";

// Randomized interleaving over a small key pool.
mt_srand(1234);
$pool = [];
for ($i = 0; $i < 6; $i++) $pool[] = random_bytes([1, 16, 32, 64, 65, 150][$i]);
$bad = 0;
for ($i = 0; $i < 2000; $i++) {
    $k = $pool[mt_rand(0, 5)];
    $o = $pool[mt_rand(0, 5)];
    $s = phpser_serialize_signed([$i, str_repeat('p', $i % 130)], $k);
    if (!tag_ok($s, $k)) $bad++;
    if (accepts($s, $o) !== ($o === $k)) $bad++;
}
echo "interleaved: ", $bad === 0 ? "OK" : "FAIL ($bad)", "\n";
?>
--EXPECT--
transition 0: OK
transition 1: OK
transition 2: OK
transition 3: OK
transition 4: OK
transition 5: OK
transition 6: OK
transition 7: OK
zero-pad equivalence: OK
hashed key equivalence: OK
64-byte key is not hashed: OK
interleaved: OK
