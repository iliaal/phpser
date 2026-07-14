--TEST--
phpser: wire integers reject values outside the target zend_long width
--EXTENSIONS--
phpser
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
?>
--EXPECT--
scalar OK
packed OK
table OK
key OK
negative keys OK
