--TEST--
phpser: crafted wire cannot create a dynamic property on a readonly class
--EXTENSIONS--
phpser
--FILE--
<?php
readonly class ReadonlyWire {
    public int $declared;
}

class DeprecatedDynamicWire {}

#[AllowDynamicProperties]
class AllowedDynamicWire {}

function dynamic_payload(string $class): string {
    return "\x01\x02"
         . chr(strlen($class)) . $class
         . "\x01x"
         . "\x0a\x00\x01"
         . "\x01\x03\x02";
}

try {
    phpser_unserialize(dynamic_payload(ReadonlyWire::class), [
        'allowed_classes' => [ReadonlyWire::class],
    ]);
    echo "accepted\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

set_error_handler(function (int $severity, string $message): bool {
    echo $severity === E_DEPRECATED ? "deprecated\n" : "wrong warning\n";
    return true;
});
$deprecated = phpser_unserialize(dynamic_payload(DeprecatedDynamicWire::class));
restore_error_handler();
echo $deprecated->x, "\n";

$allowed = phpser_unserialize(dynamic_payload(AllowedDynamicWire::class));
echo $allowed->x, "\n";

// Fail-fast: the forbidden dynamic property must reject at the key, before
// the value is decoded. If the value names a class, decoding it would fire
// the autoloader — native rejects first and never touches the value.
$autoloaded = [];
spl_autoload_register(function (string $name) use (&$autoloaded): void {
    $autoloaded[] = $name;
});
$probe = "AutoloadProbe109";
$fail_fast = "\x01\x03"
    . chr(strlen("ReadonlyWire")) . "ReadonlyWire"
    . "\x01x"
    . chr(strlen($probe)) . $probe
    . "\x0a\x00\x01"   // TAG_OBJECT class_idx=0 nprops=1
    . "\x01"           // prop key_idx=1 ("x")
    . "\x0a\x02\x00";  // value: TAG_OBJECT class_idx=2 (AutoloadProbe109) nprops=0
try {
    phpser_unserialize($fail_fast);
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo $autoloaded === [] ? "no autoload\n" : "autoloaded " . implode(',', $autoloaded) . "\n";
?>
--EXPECT--
Cannot create dynamic property ReadonlyWire::$x
deprecated
1
1
Cannot create dynamic property ReadonlyWire::$x
no autoload
