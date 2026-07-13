--TEST--
phpser: malformed wire class names reject before allowlist resolution or autoload
--EXTENSIONS--
phpser
--FILE--
<?php
class ClassNameProbe {}

$autoloaded = [];
spl_autoload_register(function (string $name) use (&$autoloaded): void {
    $autoloaded[] = $name;
});

function object_payload(string $name): string {
    return "\x01\x01" . chr(strlen($name)) . $name . "\x0a\x00\x00";
}

$resident = "\\ClassNameProbe";
$result = phpser_unserialize(object_payload($resident), [
    'allowed_classes' => [$resident],
]);
echo $result === null ? "resident rejected\n" : "resident accepted\n";

$missing = "\\MissingClassNameProbe";
$result = phpser_unserialize(object_payload($missing));
echo $result === null ? "missing rejected\n" : "missing accepted\n";
echo $autoloaded === [] ? "no autoload\n" : "autoloaded " . implode(',', $autoloaded) . "\n";
?>
--EXPECT--
resident rejected
missing rejected
no autoload
