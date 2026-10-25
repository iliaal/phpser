--TEST--
phpser: wire integers reject values outside the target zend_long width
--EXTENSIONS--
phpser
--INI--
phpser.key=cross-width-test-key-0123456789abcdef
--FILE--
<?php
$too_large = "\x80\x80\x80\x80\x20"; // zigzag(4294967296)
$scalar = "\x01\x00\x03" . $too_large;
$packed = "\x01\x00\x08\x01" . $too_large;
$table = "\x02\x01\x01k\x15\x01\x01\x00\x08" . $too_large;
$keyed = "\x01\x00\x06\x01\x00" . $too_large . "\x00";

if (PHP_INT_SIZE === 8) {
    echo phpser_unserialize($scalar) === 4294967296 ? "scalar OK\n" : "scalar FAIL\n";
    echo phpser_unserialize($packed) === [4294967296] ? "packed OK\n" : "packed FAIL\n";
    echo phpser_unserialize($table) === [['k' => 4294967296]] ? "table OK\n" : "table FAIL\n";
    $decoded = phpser_unserialize($keyed);
    echo is_array($decoded) && array_key_exists(4294967296, $decoded) ? "key OK\n" : "key FAIL\n";
} else {
    echo phpser_unserialize($scalar) === null ? "scalar OK\n" : "scalar FAIL\n";
    echo phpser_unserialize($packed) === null ? "packed OK\n" : "packed FAIL\n";
    echo phpser_unserialize($table) === null ? "table OK\n" : "table FAIL\n";
    echo phpser_unserialize($keyed) === null ? "key OK\n" : "key FAIL\n";
}

$negative_keys = [-3 => 'a', -1 => 'b', 0 => 'c'];
echo phpser_unserialize(phpser_serialize($negative_keys)) === $negative_keys
    ? "negative keys OK\n" : "negative keys FAIL\n";

// Exercise the same wire values through both authenticated wrappers. On a
// 64-bit host they are accepted; on a 32-bit host they must throw, not return
// the null used by unsigned decoding. Construct tags without narrowing values.
$secret = 'cross-width-test-key-0123456789abcdef';
$context = 'cross-width';
$subkey = hash_hmac('sha256', "phpser:$context:v1\0", $secret, true);
foreach (['signed', 'keyed'] as $mode) {
    $signing_key = $mode === 'signed' ? $secret : $subkey;
    $decode = $mode === 'signed'
        ? fn($p) => phpser_unserialize_signed($p, $secret)
        : fn($p) => phpser_unserialize_keyed($p, $context);
    foreach (['scalar' => $scalar, 'packed' => $packed, 'table' => $table, 'key' => $keyed] as $name => $frame) {
        $payload = $frame . hash_hmac('sha256', $frame, $signing_key, true);
        try {
            $value = $decode($payload);
            $ok = PHP_INT_SIZE === 8 && $value === phpser_unserialize($frame);
        } catch (Exception $e) {
            $ok = PHP_INT_SIZE === 4 && $e->getMessage() === 'phpser: signed payload failed to decode';
        }
        echo "$mode $name ", $ok ? "OK\n" : "FAIL\n";
    }
    // This decode-failure assertion runs on every width, unlike the rejection
    // branch above, so replacing either wrapper's exception with null fails CI.
    $invalid = "\x01\x00\x03"; // integer tag without its value
    try {
        $decode($invalid . hash_hmac('sha256', $invalid, $signing_key, true));
        echo "$mode malformed FAIL\n";
    } catch (Exception $e) {
        echo "$mode malformed ", $e->getMessage() === 'phpser: signed payload failed to decode' ? "OK\n" : "FAIL\n";
    }
    $null = phpser_serialize(null);
    echo "$mode null ", $decode($null . hash_hmac('sha256', $null, $signing_key, true)) === null ? "OK\n" : "FAIL\n";
}
?>
--EXPECT--
scalar OK
packed OK
table OK
key OK
negative keys OK
signed scalar OK
signed packed OK
signed table OK
signed key OK
signed malformed OK
signed null OK
keyed scalar OK
keyed packed OK
keyed table OK
keyed key OK
keyed malformed OK
keyed null OK
