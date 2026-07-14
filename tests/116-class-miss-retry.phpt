--TEST--
phpser: an autoload miss does not suppress a later attempt in the same payload
--EXTENSIONS--
phpser
--FILE--
<?php
$calls = 0;
spl_autoload_register(function (string $class) use (&$calls): void {
    if ($class !== 'LateLoaded') {
        return;
    }
    $calls++;
    if ($calls === 2) {
        eval('class LateLoaded {}');
    }
});

$frame = "\x01\x01\x0aLateLoaded\x07\x02\x0a\x00\x00\x0a\x00\x00";
$result = phpser_unserialize($frame);
echo $calls === 2 ? "autoload_calls OK\n" : "autoload_calls FAIL ($calls)\n";
echo is_array($result) && count($result) === 2 ? "shape OK\n" : "shape FAIL\n";
echo $result[1] instanceof LateLoaded ? "second_loaded OK\n" : "second_loaded FAIL\n";
?>
--EXPECT--
autoload_calls OK
shape OK
second_loaded OK
