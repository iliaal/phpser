--TEST--
phpser: minors batch B encode/decode robustness pins
--EXTENSIONS--
phpser
--FILE--
<?php
// Single-row table with a nested-array column: the packed-string prebind
// helper sees nrows < 2 and must bail cleanly (no cells[1] read).
$rows = [["tags" => ["a", "b"], "id" => 1]];
$rt = phpser_unserialize(phpser_serialize($rows));
echo ($rt == $rows) ? "prebind_single_row OK\n" : "prebind_single_row FAIL\n";

// A non-array/non-bool allowed_classes option is a ValueError with a
// determinate (NULL) out-set: no crash, and the engine stays usable after.
try {
    phpser_unserialize(phpser_serialize(1), ["allowed_classes" => 42]);
    echo "valueerror FAIL (no throw)\n";
} catch (ValueError $e) {
    echo "valueerror OK\n";
}
echo (phpser_unserialize(phpser_serialize("hi")) === "hi") ? "after_error OK\n" : "after_error FAIL\n";

// Wrap-safe length checks: a >UINT32_MAX inline-string length with no body
// must reject (NULL), never wrap pos+len into a bounded read.
$rt = phpser_unserialize("\x01\x00\x0c\xff\xff\xff\xff\x7f");
echo ($rt === null) ? "hugelen_inline OK\n" : "hugelen_inline FAIL\n";
$rt = phpser_unserialize("\x01\x01\x08stdClass\x0f\x00\xff\xff\xff\xff\x7f");
echo ($rt === null) ? "hugelen_legacy OK\n" : "hugelen_legacy FAIL\n";
// Same wrap-safe discipline on the other two length-prefixed sites changed
// in this hunk family: the front-loaded dict header and assoc key inline.
$rt = phpser_unserialize("\x01\x01\xff\xff\xff\xff\x7f");
echo ($rt === null) ? "hugelen_dict OK\n" : "hugelen_dict FAIL\n";
$rt = phpser_unserialize("\x01\x00\x06\x01\x02\xff\xff\xff\xff\x7f");
echo ($rt === null) ? "hugelen_key OK\n" : "hugelen_key FAIL\n";
?>
--EXPECT--
prebind_single_row OK
valueerror OK
after_error OK
hugelen_inline OK
hugelen_legacy OK
hugelen_dict OK
hugelen_key OK
