--TEST--
phpser: unmangled non-public property keys address declared slots
--EXTENSIONS--
phpser
--FILE--
<?php
#[AllowDynamicProperties]
class VisibilityWire {
    protected string $role = 'user';
    private string $secret = 'initial';
    public function state(): array { return [$this->role, $this->secret]; }
}

class MagicVisibilityWire extends VisibilityWire {
    public function __serialize(): array { return ['role' => 'admin']; }
}

readonly class ReadonlyVisibilityWire {
    protected string $role;
    public function role(): string { return $this->role; }
}

function keyed_object(string $class, array $properties): string {
    $dict = [$class, ...array_keys($properties)];
    $wire = "\x01" . chr(count($dict));
    foreach ($dict as $key) $wire .= chr(strlen($key)) . $key;
    $wire .= "\x0a\x00" . chr(count($properties));
    $index = 1;
    foreach ($properties as $value) {
        $wire .= chr($index++) . "\x0c" . chr(strlen($value)) . $value;
    }
    return $wire;
}

foreach ([
    ['role' => 'admin', 'secret' => 'restored'],
    ['extra' => 'dynamic', 'role' => 'admin', 'secret' => 'restored'],
    ["\0*\0role" => 'admin', "\0VisibilityWire\0secret" => 'restored'],
] as $properties) {
    $nativeWire = 'O:' . strlen(VisibilityWire::class) . ':"' . VisibilityWire::class
        . '":' . count($properties) . ':{';
    foreach ($properties as $key => $value) $nativeWire .= serialize($key) . serialize($value);
    $native = unserialize($nativeWire . '}');
    $decoded = phpser_unserialize(keyed_object(VisibilityWire::class, $properties));
    var_dump($decoded->state() === ['admin', 'restored'], (array) $decoded === (array) $native);
}

$decoded = phpser_unserialize(phpser_serialize(new MagicVisibilityWire));
$native = unserialize(serialize(new MagicVisibilityWire));
var_dump($decoded->state() === $native->state(), $decoded->state()[0] === 'admin');
$decoded = phpser_unserialize(keyed_object(ReadonlyVisibilityWire::class, ['role' => 'admin']));
var_dump($decoded->role() === 'admin');

$wire = keyed_object(VisibilityWire::class, ['role' => 'admin']);
$wire = substr($wire, 0, -7) . "\x03\x02";
try {
    phpser_unserialize($wire);
    echo "type accepted\n";
} catch (TypeError $e) {
    echo "type rejected\n";
}
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
type rejected
