--TEST--
phpser: malformed/truncated/random input safety (no crash, no read past buffer)
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php

// 1. Empty + version-only + truncated header
$malformed_static = [
    "",                                  // empty
    "\x00",                              // wrong version
    "\x01",                              // version only, no dict count
    "\x01\xff",                          // version + bogus varint cut off
    "\x01\xff\xff\xff\xff\xff\xff\xff\xff\xff", // varint > 64 bits
    "\x01\x01",                          // claims 1 dict entry but no entry
    "\x01\x01\x05",                      // claims 5-byte entry, no bytes
    "\x01\x01\x05abc",                   // partial dict entry
    "\x01\x00",                          // empty dict, no body
    "\x01\x00\x99",                      // empty dict + unknown tag
    "\x01\x00\x03",                      // TAG_LONG, no varint
    "\x01\x00\x04\x00\x00\x00",          // TAG_DOUBLE, only 3 bytes of payload
    "\x01\x00\x05\x05",                  // TAG_STR_DICT idx=5, dict empty
    "\x01\x00\x06\x05",                  // TAG_ASSOC len=5, no entries
    "\x01\x00\x07\xff\xff\xff\xff\xff",  // TAG_PACKED_MIXED len overflow
    "\x01\x00\x08\xff\xff\xff\xff\xff",  // TAG_PACKED_LONGS len overflow
    "\x01\x00\x0c\x05",                  // TAG_STR_INLINE len=5, no bytes
];

foreach ($malformed_static as $i => $bytes) {
    $rt = phpser_unserialize($bytes);
    // Just need to not crash; result can be NULL or partially decoded.
    if ($rt !== null && !is_scalar($rt) && !is_array($rt) && !is_object($rt)) {
        echo "case $i unexpected type\n";
    }
}
echo "static OK\n";

// 2. Truncate valid payloads at every length and decode each. Verifies
//    bounds checks on every read.
$valid_payloads = [
    phpser_serialize(null),
    phpser_serialize(42),
    phpser_serialize(3.14),
    phpser_serialize("hello world"),
    phpser_serialize([1, 2, 3]),
    phpser_serialize(["a" => 1, "b" => "two"]),
    phpser_serialize([[1, 2, 3], [4, 5, 6]]),
    phpser_serialize((object) ["x" => 1, "y" => "q"]),
];

foreach ($valid_payloads as $bytes) {
    for ($len = 0; $len < strlen($bytes); $len++) {
        $rt = phpser_unserialize(substr($bytes, 0, $len));
        // Don't care what; just shouldn't segfault.
    }
}
echo "truncate OK\n";

// 3. Random byte fuzz seeded for reproducibility. Each iter substitutes a
//    chunk of random bytes mid-payload, then decodes.
mt_srand(42);
foreach ($valid_payloads as $bytes) {
    $len = strlen($bytes);
    for ($iter = 0; $iter < 50; $iter++) {
        $cut = mt_rand(0, $len - 1);
        $tail = '';
        for ($k = 0; $k < 30; $k++) $tail .= chr(mt_rand(0, 255));
        $rt = phpser_unserialize(substr($bytes, 0, $cut) . $tail);
    }
}
echo "fuzz OK\n";
?>
--EXPECT--
static OK
truncate OK
fuzz OK
