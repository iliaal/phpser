--TEST--
phpser: empty associative arrays share immutable storage until modified
--EXTENSIONS--
phpser
--FILE--
<?php
$empty = [];
$empty['removed'] = true;
unset($empty['removed']);
$input = array_fill(0, 10000, $empty);
$payload = phpser_serialize($input);
unset($input);
$before = memory_get_usage();
$decoded = phpser_unserialize($payload);
var_dump(count($decoded) === 10000, $decoded[0] === []);
var_dump(memory_get_usage() - $before < 2 * 1024 * 1024);
$decoded[0]['changed'] = true;
var_dump($decoded[1] === []);
var_dump(phpser_unserialize("\x01\x00\x06\x00") === []);
$shared = phpser_unserialize("\x02\x00\x07\x02\x18\x06\x00\x10\x00");
var_dump($shared === [[], []]);
$shared[0][] = 1;
var_dump($shared === [[1], []]);
var_dump(phpser_unserialize("\x02\x00\x18\x06\x00") === []);
var_dump(phpser_unserialize("\x02\x00\x07\x02\x18\x06\x00\xff") === null);
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
