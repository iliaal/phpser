--TEST--
phpser: LEGACY native contexts isolate references and restore reentrant state
--EXTENSIONS--
phpser
spl
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
function legacy(string $class, string $data = ''): string {
    return "\x01\x01" . chr(strlen($class)) . $class
        . "\x0f\x00" . chr(strlen($data)) . $data;
}
class ThrowLegacy implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void { throw new RuntimeException('inner'); }
}
class ScopeLegacy implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void {
        $before = unserialize('O:8:"stdClass":0:{}');
        try {
            phpser_unserialize(legacy(ThrowLegacy::class), ['allowed_classes' => [ThrowLegacy::class]]);
        } catch (RuntimeException $e) {
            var_dump($e->getMessage() === 'inner');
        }
        $after = unserialize('O:8:"stdClass":0:{}');
        var_dump(get_class($before), get_class($after));
    }
}
phpser_unserialize(legacy(ScopeLegacy::class), ['allowed_classes' => [ScopeLegacy::class]]);
var_dump(get_class(unserialize('O:8:"stdClass":0:{}')));
unserialize('C:11:"ScopeLegacy":0:{}', ['allowed_classes' => [ScopeLegacy::class]]);
var_dump(get_class(unserialize('O:8:"stdClass":0:{}')));

$data = 'x:i:0;a:2:{i:0;O:8:"stdClass":0:{}i:1;r:3;};m:a:0:{}';
$frame = legacy('ArrayObject', $data);
$first = phpser_unserialize($frame);
$second = phpser_unserialize($frame);
var_dump($first[0] === $first[1], $second[0] === $second[1], $first[0] !== $second[0]);

class SerializeLegacy implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void { serialize(new stdClass()); }
}
class OuterSerialize {
    public function __serialize(): array {
        phpser_unserialize(legacy(SerializeLegacy::class));
        return [];
    }
}
$shared = new stdClass();
$roundtrip = unserialize(serialize([$shared, new OuterSerialize(), $shared]));
var_dump($roundtrip[0] === $roundtrip[2]);
?>
--EXPECT--
bool(true)
string(22) "__PHP_Incomplete_Class"
string(22) "__PHP_Incomplete_Class"
string(8) "stdClass"
bool(true)
string(22) "__PHP_Incomplete_Class"
string(22) "__PHP_Incomplete_Class"
string(8) "stdClass"
bool(true)
bool(true)
bool(true)
bool(true)
