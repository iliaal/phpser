--TEST--
phpser: keyed objects normalize numeric __unserialize data keys like native
--EXTENSIONS--
phpser
--FILE--
<?php
#[AllowDynamicProperties]
class NumericHookData {
    public array $data;
    public function __unserialize(array $data): void {
        $this->data = $data;
    }
}

$input = new NumericHookData;
foreach (['0', '1', '-1', '01', '+1', (string) PHP_INT_MAX, '9223372036854775808', 'name'] as $key) {
    $input->{$key} = 'value:' . $key;
}
$native = unserialize(serialize($input))->data;
$decoded = phpser_unserialize(phpser_serialize($input))->data;
var_dump($decoded === $native);
var_dump(isset($decoded[0]), isset($decoded['0']), array_key_exists(0, $decoded));
$count = count($decoded);
$decoded[0] = 'updated';
var_dump(count($decoded) === $count, $decoded['0'] === 'updated');
var_dump(array_keys($native) === array_keys($decoded));

$class = NumericHookData::class;
$wire = "\x01\x02" . chr(strlen($class)) . $class . "\x010"
    . "\x0a\x00\x02\x01\x18\x07\x00\x01\x03\x02";
$decoded = phpser_unserialize($wire)->data;
var_dump($decoded === [0 => 1]);
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
