--TEST--
phpser: allowed_classes retains its array during reentrant string conversion
--EXTENSIONS--
phpser
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip stringable entries require PHP 8.4'); ?>
--INI--
phpser.key=0123456789abcdef0123456789abcdef
--FILE--
<?php
class ReentrantTarget {
    public static int $hooks = 0;
    public function __unserialize(array $data): void { self::$hooks++; }
}
class ReentrantName {
    public static $convert;
    public function __toString(): string { return (self::$convert)(); }
}
class ReentrantDestructName extends ReentrantName {
    public function __destruct() { throw new LogicException('cleanup failed'); }
}
$value = new ReentrantTarget;
$plain = phpser_serialize($value);
$signed = phpser_serialize_signed($value, 'key');
$keyed = phpser_serialize_keyed($value, 'test');
$decoders = [
    'plain' => fn($options) => phpser_unserialize($plain, $options),
    'signed' => fn($options) => phpser_unserialize_signed($signed, 'key', $options),
    'keyed' => fn($options) => phpser_unserialize_keyed($keyed, 'test', $options),
];
foreach ($decoders as $name => $decode) {
    // Keep the original array alive: a referenced option can be replaced
    // during conversion. Ordinary writes use its original COW snapshot.
    foreach (['replace', 'mutate', 'nested', 'throw', 'destruct'] as $mode) {
        ReentrantTarget::$hooks = 0;
        $classes = [
            $mode === 'destruct' ? new ReentrantDestructName : new ReentrantName,
            ReentrantTarget::class,
        ];
        ReentrantName::$convert = function () use (&$classes, $mode, $decode): string {
            if ($mode === 'mutate') {
                $classes[1] = 'UnknownClass';
            } else {
                $classes = [];
            }
            if ($mode === 'nested') {
                echo 'nested=', get_class($decode(['allowed_classes' => false])), "\n";
            }
            if ($mode === 'throw') throw new LogicException('conversion failed');
            return 'UnknownClass';
        };
        try {
            $result = $decode(['allowed_classes' => &$classes]);
            echo "$name $mode=", get_class($result);
        } catch (Throwable $e) {
            echo "$name $mode=", get_class($e), ':', $e->getMessage();
        }
        echo ' hooks=', ReentrantTarget::$hooks, "\n";
        // A failure must not leak allowlist state into a later decode.
        echo 'after=', get_class($decode(['allowed_classes' => [ReentrantTarget::class]])), "\n";
        unset($classes);
        ReentrantName::$convert = null;
    }
}
?>
--EXPECT--
plain replace=ReentrantTarget hooks=1
after=ReentrantTarget
plain mutate=ReentrantTarget hooks=1
after=ReentrantTarget
nested=__PHP_Incomplete_Class
plain nested=ReentrantTarget hooks=1
after=ReentrantTarget
plain throw=LogicException:conversion failed hooks=0
after=ReentrantTarget
plain destruct=LogicException:cleanup failed hooks=0
after=ReentrantTarget
signed replace=ReentrantTarget hooks=1
after=ReentrantTarget
signed mutate=ReentrantTarget hooks=1
after=ReentrantTarget
nested=__PHP_Incomplete_Class
signed nested=ReentrantTarget hooks=1
after=ReentrantTarget
signed throw=LogicException:conversion failed hooks=0
after=ReentrantTarget
signed destruct=LogicException:cleanup failed hooks=0
after=ReentrantTarget
keyed replace=ReentrantTarget hooks=1
after=ReentrantTarget
keyed mutate=ReentrantTarget hooks=1
after=ReentrantTarget
nested=__PHP_Incomplete_Class
keyed nested=ReentrantTarget hooks=1
after=ReentrantTarget
keyed throw=LogicException:conversion failed hooks=0
after=ReentrantTarget
keyed destruct=LogicException:cleanup failed hooks=0
after=ReentrantTarget
