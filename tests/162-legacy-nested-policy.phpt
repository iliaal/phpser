--TEST--
phpser: LEGACY nested native decoding honors the class allowlist
--EXTENSIONS--
phpser
spl
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
class NestedDenied {
    public static int $woke = 0;
    public static int $destroyed = 0;
    public function __wakeup(): void { self::$woke++; }
    public function __destruct() { self::$destroyed++; }
}
function vint(int $n): string {
    $s = '';
    do {
        $b = $n & 127;
        $n >>= 7;
        $s .= chr($b | ($n ? 128 : 0));
    } while ($n);
    return $s;
}
function legacy(string $class, string $data): string {
    return "\x01\x01" . vint(strlen($class)) . $class
        . "\x0f\x00" . vint(strlen($data)) . $data;
}
$object = 'O:12:"NestedDenied":0:{}';
$payloads = [
    'ArrayObject' => 'x:i:0;a:1:{i:0;' . $object . '};m:a:0:{}',
    'SplObjectStorage' => 'x:i:1;' . $object . ',i:0;;m:a:0:{}',
    'SplDoublyLinkedList' => 'i:0;:' . $object,
];
function member($container): object {
    foreach ($container as $value) return $value;
    throw new RuntimeException('empty container');
}
foreach ($payloads as $class => $data) {
    $frame = legacy($class, $data);
    $allowed = ['allowed_classes' => [strtolower($class)]];
    $native = unserialize('C:' . strlen($class) . ':"' . $class . '":'
        . strlen($data) . ':{' . $data . '}', $allowed);
    $decoded = phpser_unserialize($frame, $allowed);
    echo $class, ': ', get_class(member($decoded)), "\n";
    var_dump(get_class(member($decoded)) === get_class(member($native)));
    unset($native, $decoded);
}
var_dump(NestedDenied::$woke, NestedDenied::$destroyed);
$frame = legacy('ArrayObject', $payloads['ArrayObject']);
$permitted = phpser_unserialize($frame, ['allowed_classes' => ['ArrayObject', 'NestedDenied']]);
var_dump(get_class(member($permitted)), NestedDenied::$woke);
unset($permitted);
var_dump(NestedDenied::$destroyed);
$denied = phpser_unserialize($frame, ['allowed_classes' => false]);
var_dump(get_class($denied));

class FromWakeup {
    public function __wakeup(): void {
        global $frame;
        $decoded = phpser_unserialize($frame, ['allowed_classes' => ['ArrayObject']]);
        echo 'wakeup: ', get_class(member($decoded)), "\n";
    }
}
unserialize('O:10:"FromWakeup":0:{}');
class FromSerialize {
    public function __serialize(): array {
        global $frame;
        $decoded = phpser_unserialize($frame, ['allowed_classes' => ['ArrayObject']]);
        echo 'serialize: ', get_class(member($decoded)), "\n";
        return [];
    }
}
serialize(new FromSerialize());
var_dump(NestedDenied::$woke, NestedDenied::$destroyed);
?>
--EXPECT--
ArrayObject: __PHP_Incomplete_Class
bool(true)
SplObjectStorage: __PHP_Incomplete_Class
bool(true)
SplDoublyLinkedList: __PHP_Incomplete_Class
bool(true)
int(0)
int(0)
string(12) "NestedDenied"
int(1)
int(1)
string(22) "__PHP_Incomplete_Class"
wakeup: __PHP_Incomplete_Class
serialize: __PHP_Incomplete_Class
int(1)
int(1)
