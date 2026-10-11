--TEST--
phpser: failed enum case evaluation preserves the native error without exposing an AST
--EXTENSIONS--
phpser
--FILE--
<?php
enum BrokenCase: string { case Value = PHPSER_MISSING_ENUM_VALUE; }
class EnumHolder { public BrokenCase $value; }
function frame(array $dict, string $body): string {
    $frame = "\x01" . chr(count($dict));
    foreach ($dict as $word) $frame .= chr(strlen($word)) . $word;
    return $frame . $body;
}
$dict = ['BrokenCase', 'Value', 'EnumHolder', 'value'];
$case = "\x0d\x00\x01";
foreach ([$case, "\x0a\x02\x01\x03" . $case] as $body) {
    try {
        phpser_unserialize(frame($dict, $body), [
            'allowed_classes' => ['BrokenCase', 'EnumHolder'],
        ]);
        echo "unexpected success\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
}
define('PHPSER_MISSING_ENUM_VALUE', 'ready');
$result = phpser_unserialize(frame($dict, "\x0a\x02\x01\x03" . $case));
var_dump($result->value === BrokenCase::Value, $result->value->value);
var_dump(phpser_unserialize(phpser_serialize(BrokenCase::Value)) === BrokenCase::Value);
?>
--EXPECT--
Undefined constant "PHPSER_MISSING_ENUM_VALUE"
Undefined constant "PHPSER_MISSING_ENUM_VALUE"
bool(true)
string(5) "ready"
bool(true)
