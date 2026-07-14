--TEST--
phpser: adversarial associative-key hash chains are bounded
--EXTENSIONS--
phpser
--FILE--
<?php
function varint(int $value): string {
    $out = '';
    while ($value >= 0x80) {
        $out .= chr(($value & 0x7f) | 0x80);
        $value >>= 7;
    }
    return $out . chr($value);
}

function collision_key(int $bits, int $pairs = 8): string {
    $key = '';
    for ($i = 0; $i < $pairs; $i++) {
        $key .= ($bits & (1 << $i)) ? "\x01\x20" : "\x00\x41";
    }
    return $key;
}

function assoc_frame(array $keys): string {
    $body = "\x01\x00\x06" . varint(count($keys));
    foreach ($keys as $key) {
        $body .= "\x02" . varint(strlen($key)) . $key . "\x00";
    }
    return $body;
}

$colliding = [];
$control = [];
for ($i = 0; $i < 128; $i++) {
    $colliding[] = collision_key($i);
    $control[] = str_pad((string) $i, 16, 'x');
}

$control_result = phpser_unserialize(assoc_frame($control), ['allowed_classes' => false]);
echo is_array($control_result) && count($control_result) === 128 ? "control OK\n" : "control FAIL\n";
echo phpser_unserialize(assoc_frame($colliding), ['allowed_classes' => false]) === null
    ? "collision_rejected OK\n" : "collision_rejected FAIL\n";
?>
--EXPECT--
control OK
collision_rejected OK
