--TEST--
phpser: signed authentication errors precede allowed_classes validation
--EXTENSIONS--
phpser
--FILE--
<?php
class SignedOptionOrderTarget {
    public static int $hooks = 0;
    public function __unserialize(array $data): void { self::$hooks++; }
}
$key = 'option-order-key';
$signed = phpser_serialize_signed(new SignedOptionOrderTarget, $key);
$tampered = $signed;
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
$badOptions = ['allowed_classes' => 42];

foreach ([
    'empty key' => [$signed, '', 'phpser: signing key must not be empty'],
    'empty payload' => ['', $key, 'phpser: signed payload too short'],
    '31-byte payload' => [str_repeat('x', 31), $key, 'phpser: signed payload too short'],
    'bad tag' => [$tampered, $key, 'phpser: signature verification failed'],
    'wrong key' => [$signed, 'other-key', 'phpser: signature verification failed'],
] as $label => [$payload, $decodeKey, $message]) {
    $outcome = null;
    try { phpser_unserialize_signed($payload, $decodeKey, $badOptions); }
    catch (Throwable $e) { $outcome = [get_class($e), $e->getMessage()]; }
    echo $label, ': ';
    var_dump($outcome === [Exception::class, $message] && SignedOptionOrderTarget::$hooks === 0);
}

// Authentication succeeding must not bypass option validation, even when
// exactly 32 authenticated bytes leave an empty (undecodable) frame.
foreach (['valid frame' => $signed, 'empty frame' => hash_hmac('sha256', '', $key, true)] as $label => $payload) {
    $outcome = null;
    try { phpser_unserialize_signed($payload, $key, $badOptions); }
    catch (Throwable $e) { $outcome = [get_class($e), $e->getMessage()]; }
    echo $label, ': ';
    var_dump($outcome === [TypeError::class,
        'phpser_unserialize_signed(): Option "allowed_classes" must be of type array|bool, int given']
        && SignedOptionOrderTarget::$hooks === 0);
}

echo 'healthy after errors: ';
var_dump(phpser_unserialize_signed($signed, $key) instanceof SignedOptionOrderTarget
    && SignedOptionOrderTarget::$hooks === 1);
?>
--EXPECT--
empty key: bool(true)
empty payload: bool(true)
31-byte payload: bool(true)
bad tag: bool(true)
wrong key: bool(true)
valid frame: bool(true)
empty frame: bool(true)
healthy after errors: bool(true)
