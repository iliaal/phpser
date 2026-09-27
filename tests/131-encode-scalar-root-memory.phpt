--TEST--
phpser: scalar root runs do not allocate an array snapshot
--SKIPIF--
<?php
if (!extension_loaded('phpser')) die('skip phpser not loaded');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit requires Zend allocator');
?>
--INI--
memory_limit=32M
--FILE--
<?php
$input = range(0, 999999);
$payload = phpser_serialize($input);
unset($input);
$decoded = phpser_unserialize($payload);
$ok = is_array($decoded) && count($decoded) === 1000000;
if ($ok) {
    foreach ($decoded as $key => $value) {
        if ($value !== $key) {
            $ok = false;
            break;
        }
    }
}
var_dump(strlen($payload) === 8, $ok);
?>
--EXPECT--
bool(true)
bool(true)
