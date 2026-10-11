--TEST--
phpser: LEGACY rejects non-serializable and uninstantiable classes before hooks
--EXTENSIONS--
phpser
spl
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
class LegacyFile extends SplFileObject implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void { echo "file hook ran\n"; }
}
abstract class AbstractLegacy implements Serializable {
    public function serialize(): string { return ''; }
    public function unserialize(string $data): void { echo "abstract hook ran\n"; }
}
class ConcreteLegacy extends AbstractLegacy {
    public string $data;
    public function unserialize(string $data): void { $this->data = $data; }
}
function legacy(string $class): string {
    return "\x01\x01" . chr(strlen($class)) . $class . "\x0f\x00\x02ok";
}
foreach ([LegacyFile::class, AbstractLegacy::class] as $class) {
    try {
        var_dump(phpser_unserialize(legacy($class)));
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    $denied = phpser_unserialize(legacy($class), ['allowed_classes' => false]);
    var_dump(get_class($denied));
}
$valid = phpser_unserialize(legacy(ConcreteLegacy::class));
var_dump(get_class($valid), $valid->data);
?>
--EXPECT--
NULL
string(22) "__PHP_Incomplete_Class"
NULL
string(22) "__PHP_Incomplete_Class"
string(14) "ConcreteLegacy"
string(2) "ok"
