--TEST--
phpser: float edge cases (INF, -INF, NAN, denormals)
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php
// INF / -INF survive equality
foreach ([INF, -INF] as $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    var_dump($v === $rt);
}

// NAN doesn't equal itself, so check via is_nan
$rt = phpser_unserialize(phpser_serialize(NAN));
var_dump(is_nan($rt));

// Subnormal
$tiny = PHP_FLOAT_MIN / 2;
$rt = phpser_unserialize(phpser_serialize($tiny));
var_dump($tiny === $rt);

// Negative zero — bit-level comparison, since 1/$rt would raise
// DivisionByZeroError in PHP 8.
$rt = phpser_unserialize(phpser_serialize(-0.0));
var_dump(pack("d", $rt) === pack("d", -0.0));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
