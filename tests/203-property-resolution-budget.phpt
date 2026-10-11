--TEST--
phpser: property resolution bounds long-key work and aborts malformed names before values
--EXTENSIONS--
phpser
--FILE--
<?php
function resolution_varint(int $value): string {
    $out = '';
    while ($value >= 128) { $out .= chr(($value & 127) | 128); $value >>= 7; }
    return $out . chr($value);
}
function resolution_frame(array $dict, string $body): string {
    $wire = "\x02" . resolution_varint(count($dict));
    foreach ($dict as $key) $wire .= resolution_varint(strlen($key)) . $key;
    return $wire . $body;
}

$a = str_repeat('a', 4096);
$b = $a . 'b';
eval('class ResolutionBudget { public $' . $a . '; public $' . $b . '; }');
$dict = [ResolutionBudget::class, $a, $b];
foreach ([false, true] as $magic) {
    foreach ([true, false] as $alternate) {
        $body = '';
        for ($i = 0; $i < 512; $i++) {
            $index = $alternate && ($i & 1) ? 2 : 1;
            $body .= $magic ? "\x0e\x00\x06\x01\x01" : '';
            $body .= chr($index) . "\x00";
        }
        $body = ($magic ? "\x07" : "\x0a\x00") . resolution_varint(512) . $body;
        $decoded = phpser_unserialize(resolution_frame($dict, $body));
        var_dump($alternate ? $decoded === null
            : ($magic ? count($decoded) === 512 && $decoded[511] instanceof ResolutionBudget
                : $decoded instanceof ResolutionBudget));
    }
}

$key = "\0" . str_repeat('A', 65536) . "\0x";
$body = "\x0a\x00" . resolution_varint(65536) . str_repeat("\x01\x00", 65536);
$decoded = phpser_unserialize(resolution_frame([stdClass::class, $key], $body));
var_dump($decoded instanceof stdClass && (array) $decoded === [$key => null]);

#[AllowDynamicProperties]
class ResolutionWithProperty { public $value; }
$events = [];
spl_autoload_register(function (string $class) use (&$events): void { $events[] = 'autoload'; });
set_error_handler(function () use (&$events): never {
    $events[] = 'notice';
    throw new RuntimeException('stop');
});
$wire = resolution_frame([ResolutionWithProperty::class, 'dynamic', "\0x", 'ResolutionMissing'],
    "\x0a\x00\x02\x01\x00\x02\x0a\x03\x00");
try {
    phpser_unserialize($wire);
} catch (RuntimeException $e) {
    var_dump($e->getMessage() === 'stop');
}
restore_error_handler();
var_dump($events === ['notice']);
var_dump(@phpser_unserialize($wire) === null);

$wire = resolution_frame([stdClass::class, "\0x"], "\x0a\x00\x01\x01\x00");
$native = unserialize('O:8:"stdClass":1:{s:2:"' . "\0x" . '";N;}');
$decoded = phpser_unserialize($wire);
var_dump($decoded instanceof stdClass && (array) $decoded === (array) $native);
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
