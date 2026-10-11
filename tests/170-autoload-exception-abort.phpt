--TEST--
phpser: a class autoload exception aborts every object wire form immediately
--EXTENSIONS--
phpser
--FILE--
<?php
class Later {
    public function __destruct() { echo "unexpected destructor\n"; }
}
$calls = [];
spl_autoload_register(function ($class) use (&$calls) {
    $calls[] = $class;
    throw new RuntimeException($class);
});
$forms = [
    'object' => "\x0a\x00\x00",
    'magic' => "\x0e\x00\x07\x00",
    'legacy' => "\x0f\x00\x00",
    'slots' => "\x12\x00\x00",
    'enum' => "\x0d\x00\x01",
];
foreach ($forms as $name => $body) {
    $calls = [];
    $frame = "\x02\x03\x07Missing\x05Other\x05Later\x07\x03"
        . $body . "\x0a\x01\x00\x0a\x02\x00";
    try {
        phpser_unserialize($frame);
        echo "unexpected success\n";
    } catch (RuntimeException $e) {
        echo $name, ': ', $e->getMessage(), ', calls=', count($calls),
            ', previous=', $e->getPrevious() === null ? 'none' : 'chained', "\n";
    }
}
$value = phpser_unserialize(phpser_serialize(new stdClass));
var_dump($value instanceof stdClass);
?>
--EXPECT--
object: Missing, calls=1, previous=none
magic: Missing, calls=1, previous=none
legacy: Missing, calls=1, previous=none
slots: Missing, calls=1, previous=none
enum: Missing, calls=1, previous=none
bool(true)
