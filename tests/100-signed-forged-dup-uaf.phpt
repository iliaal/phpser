--TEST--
phpser: signed (trusted) forged duplicate keys — object prop + back-ref UAF and assoc dup/numeric coercion
--EXTENSIONS--
phpser
--FILE--
<?php
// =====================================================================
// A valid HMAC proves key possession, not honest-encoder provenance: a
// forged-but-signed frame can carry duplicate keys the encoder never emits.
// The signed (trusted) path once skipped the id-table object pin and used
// unconditional add_new for assoc, so:
//   CR-001: a duplicate object-property key whose first value is a nested,
//           id-registered object frees it on overwrite; a later TAG_REF then
//           deref/addref-writes freed memory (heap UAF).
//   CR-003: a duplicate/numeric assoc key produced phantom buckets and left
//           canonical-numeric string keys ("5") as string buckets.
// Fix: pin every registered object unconditionally (dec_register) and use
// last-write-wins update semantics on the trusted assoc path too. Under
// valgrind/ASan the pre-fix CR-001 frame crashes.
// =====================================================================
function v($n){ $o=""; while($n>=0x80){ $o.=chr(($n&0x7f)|0x80); $n>>=7;} return $o.chr($n); }
$key = str_repeat("k", 32);
function sign($body,$key){ return $body . hash_hmac('sha256',$body,$key,true); }

// --- CR-001: PACKED_MIXED[ OBJECT stdClass{ "a"=>OBJECT stdClass(id1), "a"=>null }, REF(id1) ] ---
$body =
    "\x01" . v(2) . v(8)."stdClass" . v(1)."a" .   // v1, dict = [stdClass, a]
    "\x07" . v(2) .                                // PACKED_MIXED, 2 elems
      "\x0a" . v(0) . v(2) .                       // OBJECT stdClass(0), nprops=2  (id0)
        v(1) . "\x0a" . v(0) . v(0) .              //   prop "a"(1) = OBJECT stdClass(0){}  (id1)
        v(1) . "\x00" .                            //   prop "a"(1) = NULL  (overwrites -> frees id1 pre-fix)
      "\x10" . v(1);                               // REF id1 -> the displaced object
$r = phpser_unserialize_signed(sign($body,$key), $key);
var_dump(is_array($r) && count($r) === 2);        // no crash, well-formed
var_dump($r[0]->a);                               // last-write-wins: null
var_dump($r[1] instanceof stdClass);              // back-ref resolves to the pinned object

// --- CR-003: signed TAG_ASSOC with a duplicate key and a canonical-numeric key ---
$body2 =
    "\x01" . v(0) .                                // v1, empty dict
    "\x06" . v(3) .                                // TAG_ASSOC, 3 entries
      "\x02" . v(1)."k" . "\x03" . chr(2) .        //   "k" = 1   (KEY_STR_INLINE, TAG_LONG zigzag)
      "\x02" . v(1)."k" . "\x03" . chr(4) .        //   "k" = 2   (duplicate -> last wins)
      "\x02" . v(1)."5" . "\x03" . chr(6);         //   "5" = 3   (coerces to int key 5)
$a = phpser_unserialize_signed(sign($body2,$key), $key);
var_dump(count($a) === 2);                         // no phantom bucket
var_dump($a['k'] === 2);                           // last-write-wins
var_dump(array_key_exists(5, $a) && $a[5] === 3);  // numeric string coerced to int 5
var_dump(array_keys($a) === ['k', 5]);             // key is int 5, no stray string "5" bucket
echo "ok\n";
?>
--EXPECT--
bool(true)
NULL
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
ok
