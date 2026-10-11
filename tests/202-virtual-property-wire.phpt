--TEST--
phpser: virtual properties reject wire values without invoking property hooks
--EXTENSIONS--
phpser
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip property hooks require PHP 8.4'); ?>
--FILE--
<?php
#[AllowDynamicProperties]
class VirtualWire {
    public static int $calls = 0;
    public string $backed = 'initial' {
        set { self::$calls++; $this->backed = $value; }
    }
    public string $vh { get { self::$calls++; return 'virtual'; } }
    private string $hidden { get { self::$calls++; return 'private'; } }
}

class InheritedVirtualWire extends VirtualWire {}

class MagicVirtualWire extends VirtualWire {
    public function __serialize(): array { return ['vh' => 'shadow']; }
}

class VirtualHookConsumer extends VirtualWire {
    public array $data;
    public function __unserialize(array $data): void { $this->data = $data; }
}

function virtual_payload(string $key, bool $materialize = false, bool $autoload = false,
    string $class = VirtualWire::class): string {
    $dict = [$class, $key, 'dynamic', 'VirtualValueProbe'];
    $wire = "\x01" . chr(count($dict));
    foreach ($dict as $item) $wire .= chr(strlen($item)) . $item;
    $wire .= "\x0a\x00" . chr($materialize ? 2 : 1);
    if ($materialize) $wire .= "\x02\x00";
    return $wire . "\x01" . ($autoload ? "\x0a\x03\x00" : "\x0c\x06shadow");
}

foreach (['vh', "\0*\0vh", "\0VirtualWire\0vh"] as $key) {
    $native = 'O:' . strlen(VirtualWire::class) . ':"' . VirtualWire::class
        . '":1:{' . serialize($key) . serialize('shadow') . '}';
    var_dump(@unserialize($native) === false);
    foreach ([false, true] as $materialize) {
        var_dump(@phpser_unserialize(virtual_payload($key, $materialize)) === null);
    }
}

$autoloaded = [];
spl_autoload_register(function (string $name) use (&$autoloaded): void { $autoloaded[] = $name; });
var_dump(@phpser_unserialize(virtual_payload('vh', false, true)) === null);
var_dump($autoloaded === []);
var_dump(@phpser_unserialize(phpser_serialize(new MagicVirtualWire)) === null);
var_dump(VirtualWire::$calls === 0);

$decoded = phpser_unserialize(phpser_serialize(new VirtualWire));
var_dump($decoded instanceof VirtualWire, $decoded->backed === 'initial');
$decoded = phpser_unserialize(virtual_payload('backed'));
var_dump($decoded->backed === 'shadow', VirtualWire::$calls === 0);
$decoded = phpser_unserialize(virtual_payload('vh'), ['allowed_classes' => false]);
var_dump($decoded instanceof __PHP_Incomplete_Class, ((array) $decoded)['vh'] === 'shadow');
$decoded = phpser_unserialize(virtual_payload('vh', false, false, VirtualHookConsumer::class));
var_dump($decoded->data === ['vh' => 'shadow']);
$class = InheritedVirtualWire::class;
$key = "\0VirtualWire\0hidden";
$native = unserialize('O:' . strlen($class) . ':"' . $class . '":1:{'
    . serialize($key) . serialize('shadow') . '}');
foreach ([false, true] as $materialize) {
    $decoded = phpser_unserialize(virtual_payload($key, $materialize, false, $class));
    $properties = (array) $decoded;
    if ($materialize) unset($properties['dynamic']);
    var_dump($decoded instanceof InheritedVirtualWire && $properties === (array) $native);
}
$decoded = phpser_unserialize(phpser_serialize(new InheritedVirtualWire));
var_dump($decoded instanceof InheritedVirtualWire && $decoded->backed === 'initial');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
