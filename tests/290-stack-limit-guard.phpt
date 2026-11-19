--TEST--
phpser: nesting deeper than the remaining C stack fails cleanly instead of crashing
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) die("skip zend.max_allowed_stack_size needs PHP 8.3+");
// Sanitizer builds reserve more than 128K, leaving no usable stack at all.
$probe = shell_exec(escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' -n -d zend.max_allowed_stack_size=128K -r "echo 1;" 2>&1');
if ($probe !== '1') die("skip zend.reserved_stack_size exceeds 128K on this build");
?>
--INI--
zend.max_allowed_stack_size=128K
--FILE--
<?php
// 511 levels need more C stack than the 128K limit leaves usable.
$a = 42;
for ($i = 0; $i < 511; $i++) { $a = [$a]; }
try {
    phpser_serialize($a);
    echo "encode: no error\n";
} catch (Error $e) {
    echo "encode: ", str_contains($e->getMessage(), "Maximum call stack size") ? "stack error" : $e->getMessage(), "\n";
}
$deep = "\x01\x00" . str_repeat("\x07\x01", 510) . "\x00";
var_dump(phpser_unserialize($deep) === null);
$shallow = "\x01\x00" . str_repeat("\x07\x01", 16) . "\x00";
var_dump(phpser_unserialize($shallow) !== null);
$a = 42;
for ($i = 0; $i < 16; $i++) { $a = [$a]; }
var_dump(phpser_unserialize(phpser_serialize($a)) === $a);
?>
--EXPECT--
encode: stack error
bool(true)
bool(true)
bool(true)
