--TEST--
phpser: signed authentication precedes stringable allowed_classes callbacks
--EXTENSIONS--
phpser
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip stringable entries require PHP 8.4'); ?>
--FILE--
<?php
class SignedCallbackTarget {
    public static array $events = [];
    public function __unserialize(array $data): void { self::$events[] = 'decode'; }
}
class SignedCallbackName {
    public ?Throwable $error = null;
    public function __toString(): string {
        SignedCallbackTarget::$events[] = 'option';
        if ($this->error) throw $this->error;
        return SignedCallbackTarget::class;
    }
}
$key = 'callback-order-key';
$signed = phpser_serialize_signed(new SignedCallbackTarget, $key);
$tampered = $signed;
$tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
$name = new SignedCallbackName;
$name->error = new LogicException('option conversion failed');
$options = ['allowed_classes' => [$name]];

foreach ([
    'short payload' => ['x', $key, 'phpser: signed payload too short'],
    'bad tag' => [$tampered, $key, 'phpser: signature verification failed'],
    'wrong key' => [$signed, 'other-key', 'phpser: signature verification failed'],
] as $label => [$payload, $decodeKey, $message]) {
    SignedCallbackTarget::$events = [];
    $outcome = null;
    try { phpser_unserialize_signed($payload, $decodeKey, $options); }
    catch (Throwable $e) { $outcome = [get_class($e), $e->getMessage()]; }
    echo $label, ': ';
    var_dump($outcome === [Exception::class, $message] && SignedCallbackTarget::$events === []);
}

SignedCallbackTarget::$events = [];
$caught = null;
try { phpser_unserialize_signed($signed, $key, $options); }
catch (Throwable $e) { $caught = $e; }
echo 'authenticated conversion failure: ';
var_dump($caught === $name->error && SignedCallbackTarget::$events === ['option']);

// A successful conversion must run once before the decoded object's hook.
SignedCallbackTarget::$events = [];
$name->error = null;
$value = phpser_unserialize_signed($signed, $key, $options);
echo 'authenticated conversion success: ';
var_dump($value instanceof SignedCallbackTarget && SignedCallbackTarget::$events === ['option', 'decode']);

SignedCallbackTarget::$events = [];
echo 'healthy after callbacks: ';
var_dump(phpser_unserialize_signed($signed, $key) instanceof SignedCallbackTarget
    && SignedCallbackTarget::$events === ['decode']);
?>
--EXPECT--
short payload: bool(true)
bad tag: bool(true)
wrong key: bool(true)
authenticated conversion failure: bool(true)
authenticated conversion success: bool(true)
healthy after callbacks: bool(true)
