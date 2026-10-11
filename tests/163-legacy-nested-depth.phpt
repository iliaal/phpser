--TEST--
phpser: LEGACY nested native arrays share the remaining decode depth
--EXTENSIONS--
phpser
spl
--FILE--
<?php
function vint(int $n): string {
    $s = '';
    do {
        $b = $n & 127;
        $n >>= 7;
        $s .= chr($b | ($n ? 128 : 0));
    } while ($n);
    return $s;
}
function nested(int $nativeDepth, int $outerDepth = 0): string {
    $data = 'x:i:0;' . str_repeat('a:1:{i:0;', $nativeDepth)
        . 'i:1;' . str_repeat('}', $nativeDepth) . ';m:a:0:{}';
    return "\x01\x01\x0bArrayObject" . str_repeat("\x07\x01", $outerDepth)
        . "\x0f\x00" . vint(strlen($data)) . $data;
}
function checkDepth(int $nativeDepth, int $outerDepth, bool $fits): void {
    try {
        $value = @phpser_unserialize(nested($nativeDepth, $outerDepth));
        for ($i = 0; $i < $outerDepth; $i++) $value = $value[0];
        var_dump($fits && $value instanceof ArrayObject);
    } catch (UnexpectedValueException $e) {
        var_dump(!$fits);
    }
}
ini_set('unserialize_max_depth', '4');
checkDepth(3, 0, true);
checkDepth(4, 0, false);
checkDepth(1, 2, true);
checkDepth(2, 2, false);
ini_set('unserialize_max_depth', '0');
checkDepth(511, 0, true);
checkDepth(512, 0, false);
var_dump(unserialize('a:1:{i:0;a:1:{i:0;a:1:{i:0;a:1:{i:0;a:0:{}}}}}')
    === [[[[[]]]]]);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
