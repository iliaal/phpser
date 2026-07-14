--TEST--
phpser: older TAG_OBJECT_SLOTS payloads decode into append-only class schemas
--EXTENSIONS--
phpser
--FILE--
<?php
class EvolvableSlots {
    public int $id = 7;
    public string $appended = 'default';
}

function v(int $value): string {
    $out = '';
    while ($value >= 0x80) {
        $out .= chr(($value & 0x7f) | 0x80);
        $value >>= 7;
    }
    return $out . chr($value);
}

$class = EvolvableSlots::class;
$old = "\x02" . v(1) . v(strlen($class)) . $class
    . "\x12" . v(0) . v(1) . "\x03" . v(84);

$decoded = phpser_unserialize($old);
echo $decoded instanceof EvolvableSlots && $decoded->id === 42
    && $decoded->appended === 'default' ? "unsigned_append OK\n" : "unsigned_append FAIL\n";

$key = 'slots-evolution-key';
$signed = $old . hash_hmac('sha256', $old, $key, true);
try {
    $decoded = phpser_unserialize_signed($signed, $key);
    echo $decoded instanceof EvolvableSlots && $decoded->id === 42
        && $decoded->appended === 'default' ? "signed_append OK\n" : "signed_append FAIL\n";
} catch (Exception $e) {
    echo "signed_append FAIL\n";
}

$denied = phpser_unserialize($old, ['allowed_classes' => false]);
$props = (array) $denied;
echo ($props['id'] ?? null) === 42 && !array_key_exists('appended', $props)
    ? "denied_prefix OK\n" : "denied_prefix FAIL\n";

$too_many = "\x02" . v(1) . v(strlen($class)) . $class
    . "\x12" . v(0) . v(3) . "\x03" . v(2) . "\x03" . v(4) . "\x03" . v(6);
echo phpser_unserialize($too_many) === null ? "newer_payload_rejected OK\n" : "newer_payload_rejected FAIL\n";
?>
--EXPECT--
unsigned_append OK
signed_append OK
denied_prefix OK
newer_payload_rejected OK
