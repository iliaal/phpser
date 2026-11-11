--TEST--
phpser: dictionary boundaries and partial-header cleanup need no sentinel slot
--EXTENSIONS--
phpser
--INI--
phpser.key=dictionary-test-key-0123456789abcdef
--FILE--
<?php
function varint(int $v): string {
    $out = '';
    do {
        $byte = $v & 0x7f;
        $v >>= 7;
        $out .= chr($byte | ($v ? 0x80 : 0));
    } while ($v);
    return $out;
}
$key = ini_get('phpser.key');
$subkey = hash_hmac('sha256', "phpser:redis:v1\0", $key, true);
$decoders = [
    'plain' => fn($s) => phpser_unserialize($s),
    'signed' => fn($s) => phpser_unserialize_signed($s . hash_hmac('sha256', $s, $key, true), $key),
    'keyed' => fn($s) => phpser_unserialize_keyed($s . hash_hmac('sha256', $s, $subkey, true), 'redis'),
];
foreach ($decoders as $mode => $decode) {
    $valid = $invalid = 0;
    foreach ([1, 2] as $version) {
        foreach ([0, 1, 2, 7, 8, 15, 16, 31, 32, 511, 512, 513, 1024] as $count) {
            $header = chr($version) . varint($count);
            for ($i = 0; $i < $count; $i++) {
                // Heap strings (including NUL) exercise ownership on failure.
                $entry = "entry\0$i";
                $header .= varint(strlen($entry)) . $entry;
            }
            if ($decode($header . "\x02") === true) $valid++;
            if ($count && $decode($header . "\x05" . varint($count - 1)) === "entry\0" . ($count - 1)) $valid++;
            $bad = [$header . "\x05" . varint($count)];
            if ($count) {
                // Reject after all but the final string were materialized.
                $bad[] = substr($header, 0, -1);
                // Reject an overlong first string after allocating the table.
                $bad[] = chr($version) . varint($count) . varint(100000) . str_repeat('x', $count);
            }
            foreach ($bad as $frame) {
                if ($mode === 'plain') {
                    if ($decode($frame) === null) $invalid++;
                } else {
                    try { $decode($frame); }
                    catch (Exception $e) {
                        // Authentication must succeed so this reaches the decoder.
                        if (str_contains($e->getMessage(), 'decode')) $invalid++;
                    }
                }
            }
        }
    }
    echo "$mode: $valid valid, $invalid rejected\n";
    echo 'healthy after errors: ';
    var_dump($decode(phpser_serialize(['key' => 'value'])) === ['key' => 'value']);
}
?>
--EXPECT--
plain: 50 valid, 74 rejected
healthy after errors: bool(true)
signed: 50 valid, 74 rejected
healthy after errors: bool(true)
keyed: 50 valid, 74 rejected
healthy after errors: bool(true)
