--TEST--
phpser: round-2 review regressions — CR-001 dup-key UAF, CR-002 dict UAF
--EXTENSIONS--
phpser
--FILE--
<?php

// =====================================================================
// CR-001: decoder id_table holds raw zend_object*/zend_reference* without
// refcount bump. A crafted payload with a duplicate assoc key collapses
// to the last value; zend_hash_update destroys the bucket holding the
// previously-registered object, freeing it. A subsequent TAG_REF to that
// id then deref's freed memory — observable as zend_mm_heap corrupted.
//
// Repro:
//   TAG_PACKED_MIXED count=2
//     [0] TAG_ASSOC count=2
//           KEY "k" + TAG_OBJECT class=0 nprops=0   (claims id 0)
//           KEY "k" + TAG_LONG 1                    (dup-key destroys obj)
//     [1] TAG_REF id=0                              (UAF)
// =====================================================================
$payload = "\x01"                       // version
         . "\x01"                       // dict_len=1
         . "\x01" . "k"                 // dict[0] = "k"
         . "\x07" . "\x02"              // TAG_PACKED_MIXED count=2
         . "\x06" . "\x02"              //   TAG_ASSOC count=2
         . "\x01" . "\x00"              //     KEY_STR dict_idx=0
         . "\x0a" . "\x00" . "\x00"     //     TAG_OBJECT class=0 nprops=0 (id 0)
         . "\x01" . "\x00"              //     KEY_STR dict_idx=0
         . "\x03" . "\x02"              //     TAG_LONG zigzag(1)
         . "\x10" . "\x00";             //   TAG_REF id=0
$rt = phpser_unserialize($payload);
// Critical: must survive. Either a sane decoded value (back-ref resolves
// to the still-alive object) or a controlled NULL — but not a crash.
echo is_array($rt) ? "cr001_dupkey_no_uaf OK\n" : "cr001_dupkey_no_uaf FAIL\n";

// Repeat under a stress loop to give the allocator a chance to reuse the
// freed chunk before the back-ref reads it. Without the fix this rapidly
// surfaces as heap corruption.
for ($i = 0; $i < 200; $i++) {
    $rt = phpser_unserialize($payload);
    // Allocate/free churn to push the freelist past the just-freed slot.
    $j = array_fill(0, 32, str_repeat("a", 24));
    unset($j);
}
echo "cr001_dupkey_stress OK\n";

// Same vuln via TAG_OBJECT dup-prop on a typed slot — decoder uses
// zval_ptr_dtor(slot) on overwrite, which would free a registered obj.
// Hand-craft: a stdClass with two writes to the same prop key.
class T_dup { public $p; }
$buf = "\x01"                    // version
     . "\x02"                    // dict_len=2
     . "\x05" . "T_dup"          // dict[0] "T_dup"
     . "\x01" . "p"              // dict[1] "p"
     . "\x07" . "\x02"           // PACKED_MIXED count=2
     . "\x0a" . "\x00" . "\x02"  //   TAG_OBJECT class=0 nprops=2 (claims id 0)
     . "\x01"                    //     key_idx=1 ("p")
     . "\x0a" . "\x01" . "\x00"  //     val = TAG_OBJECT class=1 nprops=0 (claims id 1)
     . "\x01"                    //     key_idx=1 ("p") DUP
     . "\x03" . "\x02"           //     val = TAG_LONG 1 (destroys the id-1 stdClass)
     . "\x10" . "\x01";          //   TAG_REF id=1 — second element, would UAF without fix
$rt = phpser_unserialize($buf);
echo is_array($rt) ? "cr001_dupprop_no_uaf OK\n" : "cr001_dupprop_no_uaf FAIL\n";

// =====================================================================
// CR-002: encode dict borrows zend_string pointers. Magic-method paths
// (__sleep, __serialize) destroy temporaries mid-encode; if the dict
// borrowed a string from one of those temps, the header emission later
// reads freed memory and writes corrupted bytes into the wire frame.
//
// Decode then either picks up garbage from the dict, OR crashes if the
// allocator already reused the chunk.
// =====================================================================
class S_magic {
    public function __serialize(): array {
        // Runtime-built string, unique allocation (longer than the
        // interned-string short-string optimization window). Used twice
        // so it triggers the inline->dict upgrade in the encoder.
        $val = "val_" . str_repeat("y", 32);
        return ['k1' => $val, 'k2' => $val];
    }
    public function __unserialize(array $d): void {
        $this->got_k1 = $d['k1'];
        $this->got_k2 = $d['k2'];
    }
}
#[\AllowDynamicProperties]
class S_magic_holder extends S_magic {}
$o = new S_magic_holder();
$rt = phpser_unserialize(phpser_serialize($o));
$expect = "val_" . str_repeat("y", 32);
echo ($rt->got_k1 === $expect && $rt->got_k2 === $expect)
    ? "cr002_serialize_dict OK\n" : "cr002_serialize_dict FAIL\n";

// Same shape with __sleep — dynamic property names landing in the dict.
#[\AllowDynamicProperties]
class S_sleep {
    public function __sleep(): array {
        $name = "dynprop_" . str_repeat("x", 16);
        $this->$name = "value_" . str_repeat("z", 16);
        return [$name];
    }
}
$o = new S_sleep();
$rt = phpser_unserialize(phpser_serialize($o));
$key = "dynprop_" . str_repeat("x", 16);
$val = "value_" . str_repeat("z", 16);
echo (isset($rt->$key) && $rt->$key === $val)
    ? "cr002_sleep_dict OK\n" : "cr002_sleep_dict FAIL key=" . ($rt->$key ?? 'unset') . "\n";

// Stress loop to push allocator churn through and catch latent UAF
// even if the simple round-trip happens to land on un-reused memory.
for ($i = 0; $i < 500; $i++) {
    $junk = array_fill(0, 16, str_repeat("c", 40));   // size-class collision
    $rt = phpser_unserialize(phpser_serialize(new S_magic_holder()));
    if ($rt->got_k1 !== $expect || $rt->got_k2 !== $expect) {
        echo "cr002_stress FAIL at i=$i k1=$rt->got_k1 k2=$rt->got_k2\n";
        exit(1);
    }
    unset($junk);
}
echo "cr002_stress OK\n";

// Confirm interned-literal fast path still works (zend_string_copy on
// IS_STR_INTERNED is a no-op — should produce identical bytes for the
// common case).
class C_lit { public $a = "hello"; public $b = "world"; }
$rt = phpser_unserialize(phpser_serialize(new C_lit()));
echo ($rt->a === "hello" && $rt->b === "world")
    ? "cr002_intern_fastpath OK\n" : "cr002_intern_fastpath FAIL\n";

?>
--EXPECT--
cr001_dupkey_no_uaf OK
cr001_dupkey_stress OK
cr001_dupprop_no_uaf OK
cr002_serialize_dict OK
cr002_sleep_dict OK
cr002_stress OK
cr002_intern_fastpath OK
