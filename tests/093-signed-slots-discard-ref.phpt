--TEST--
phpser: signed decode — denied, unloaded TAG_OBJECT_SLOTS whose discarded slot value is back-referenced (id-table pin safety)
--EXTENSIONS--
phpser
--FILE--
<?php
// Hand-built v2 frame: PACKED_MIXED[ SLOTS(Foo, 1 slot = stdClass{x:42}), REF -> that stdClass ].
// "Foo" is never defined here, so the denied SLOTS path resolves no schema and
// consumes-then-discards its slot value. On the signed (trusted) path that value
// skips the id-table pin, so a later TAG_REF to it would deref freed memory —
// this pins the discarded value's lifetime. Regression guard for the
// discard-frees-a-referenced-object UAF (crashes zend_mm without the fix).
function v($n){ $o=""; while($n>=0x80){ $o.=chr(($n&0x7f)|0x80); $n>>=7;} return $o.chr($n); }
$key = str_repeat("k",32);
$frame =
    "\x02" . v(3) .                       // v2 header, dict of 3
    v(3)."Foo" . v(8)."stdClass" . v(1)."x" .
    "\x07" . v(2) .                       // TAG_PACKED_MIXED, 2 elems
      "\x12" . v(0) . v(1) .              // elem0: TAG_OBJECT_SLOTS Foo(0), nprops=1
        "\x0a" . v(1) . v(1) .            //   slot val: TAG_OBJECT stdClass(1), 1 prop -> id 1
          v(2) . "\x03" . chr(84) .       //     key "x"(2) = TAG_LONG zigzag(42)=84
      "\x10" . v(1);                      // elem1: TAG_REF id=1 -> the discarded stdClass
$sig = $frame . hash_hmac('sha256', $frame, $key, true);

$r = phpser_unserialize_signed($sig, $key, ["allowed_classes" => false]);
var_dump(is_array($r) && count($r) === 2);
$a0 = (array)$r[0];  // cast reads the prop table without the incomplete-object warning
$a1 = (array)$r[1];
var_dump($a0['__PHP_Incomplete_Class_Name']);
var_dump($a1['__PHP_Incomplete_Class_Name']);
var_dump($a1['x']);
?>
--EXPECT--
bool(true)
string(3) "Foo"
string(8) "stdClass"
int(42)
