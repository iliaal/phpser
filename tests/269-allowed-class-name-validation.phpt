--TEST--
phpser: allowed_classes rejects invalid class names like native unserialize on PHP 8.4+
--EXTENSIONS--
phpser
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip class name validation added in PHP 8.4'); ?>
--INI--
phpser.key=0123456789abcdef0123456789abcdef
--FILE--
<?php
class AllowedNameProbe {
    public static int $calls = 0;
    public function __unserialize(array $data): void { self::$calls++; }
}
$value = new AllowedNameProbe;
$plain = phpser_serialize($value);
$signed = phpser_serialize_signed($value, 'key');
$keyed = phpser_serialize_keyed($value, 'test');
$native = serialize($value);
$decoders = [
    'native' => fn($options) => unserialize($native, $options),
    'plain' => fn($options) => phpser_unserialize($plain, $options),
    'signed' => fn($options) => phpser_unserialize_signed($signed, 'key', $options),
    'keyed' => fn($options) => phpser_unserialize_keyed($keyed, 'test', $options),
];
foreach ($decoders as $name => $decode) {
    AllowedNameProbe::$calls = 0;
    $rejected = 0;
    foreach (['bad name', "bad\0name", 'bad-name', 'bad/name'] as $invalid) {
        // Both the option and its entries may be references.
        $classes = [AllowedNameProbe::class, &$invalid];
        try {
            $decode(['allowed_classes' => &$classes]);
        } catch (ValueError $e) {
            $rejected++;
        }
        unset($classes);
    }
    echo "$name rejected=$rejected hooks=", AllowedNameProbe::$calls, "\n";
    // Use Zend's validity rules, not an identifier regex: these are accepted
    // allowlist strings even when they do not name an existing class.
    $valid = ['', '123', '\\Unknown\\Name', 'Unknown\\Name', 'Éclair', 'ALLOWEDNAMEPROBE'];
    $decoded = $decode(['allowed_classes' => $valid]);
    echo "$name valid=", get_class($decoded), " hooks=", AllowedNameProbe::$calls, "\n";
}
?>
--EXPECT--
native rejected=4 hooks=0
native valid=AllowedNameProbe hooks=1
plain rejected=4 hooks=0
plain valid=AllowedNameProbe hooks=1
signed rejected=4 hooks=0
signed valid=AllowedNameProbe hooks=1
keyed rejected=4 hooks=0
keyed valid=AllowedNameProbe hooks=1
