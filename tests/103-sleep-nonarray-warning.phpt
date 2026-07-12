--TEST--
phpser: a non-array __sleep return emits E_WARNING and serializes null (matches native)
--EXTENSIONS--
phpser
--FILE--
<?php
// __sleep returning a non-array is a lossy case: native serialize() emits an
// E_WARNING and writes null in the object's place. phpser previously wrote the
// null silently (comment claimed "warned" but no warning was emitted). It now
// matches native — same message, same null result (CR-005). A thrown exception
// still aborts the whole frame; only the no-exception non-array return warns.
class S_badsleep {
    public $x = 1;
    public function __sleep() { return "nope"; }
}
$blob = phpser_serialize(new S_badsleep());
var_dump(phpser_unserialize($blob));
?>
--EXPECTF--
Warning: phpser_serialize(): S_badsleep::__sleep() should return an array only containing the names of instance-variables to serialize in %s on line %d
NULL
