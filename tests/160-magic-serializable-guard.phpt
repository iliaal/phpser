--TEST--
phpser: MAGIC rejects Serializable-only classes without bypassing their hook
--EXTENSIONS--
phpser
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
class LegacyAccount implements Serializable {
    public bool $admin = false;
    public function serialize(): string { return 'account'; }
    public function unserialize(string $data): void {
        if ($data !== 'account') throw new RuntimeException('invalid account');
    }
}
class ModernAccount extends LegacyAccount {
    public function __serialize(): array { return ['admin' => $this->admin]; }
    public function __unserialize(array $data): void { $this->admin = $data['admin']; }
}
function magic(string $class): string {
    return "\x01\x01" . chr(strlen($class)) . $class
        . "\x0e\x00\x06\x01\x02\x05admin\x02";
}
var_dump(phpser_unserialize(magic(LegacyAccount::class)));
$denied = phpser_unserialize(magic(LegacyAccount::class), ['allowed_classes' => false]);
var_dump(get_class($denied), ((array)$denied)['admin']);
$legacy = phpser_unserialize(phpser_serialize(new LegacyAccount()));
var_dump(get_class($legacy), $legacy->admin);
$modern = phpser_unserialize(magic(ModernAccount::class));
var_dump(get_class($modern), $modern->admin);
?>
--EXPECT--
NULL
string(22) "__PHP_Incomplete_Class"
bool(true)
string(13) "LegacyAccount"
bool(false)
string(13) "ModernAccount"
bool(true)
