--TEST--
phpser: wire-v2 decode edges — SLOTS __unserialize routing, no-autoload on deny, typed-slot reject, ASSOC_DICT dup/numeric keys
--SKIPIF--
<?php if (!extension_loaded("phpser")) die("skip phpser not loaded"); ?>
--FILE--
<?php
// Adversarial / regression coverage for the wire-v2 tags. These frames are
// hand-crafted: TAG_OBJECT_SLOTS (0x12) and TAG_ASSOC_DICT (0x13) are never
// emitted for the shapes below by the encoder, so only crafted bytes exercise
// these decoder branches. See findings CR-001, CR-002, CR-010.

// --- CR-001: a crafted SLOTS frame for a class that HAS __unserialize must
// route to __unserialize (like TAG_OBJECT), not install raw slots + __wakeup.
// The encoder never emits SLOTS for such a class, so this is adversarial wire
// or a class that gained __unserialize after the payload was written. ---
class TU {
    public int $x = 0;
    public static $received = null;
    public static $woke = false;
    public function __unserialize(array $d) { self::$received = $d; }
    public function __wakeup() { self::$woke = true; }
}
// [0x02 v2][ndict=1][len=2]"TU"[0x12 SLOTS][class_idx=0][nprops=1][0x03 LONG][zigzag(42)=84=0x54]
$slots_unser = "\x02\x01\x02TU\x12\x00\x01\x03\x54";
$o = phpser_unserialize($slots_unser);
echo ($o instanceof TU) ? "unser_type OK\n" : "unser_type FAIL\n";
echo (TU::$received === ['x' => 42]) ? "unser_called OK\n"
    : "unser_called FAIL " . var_export(TU::$received, true) . "\n";
echo (TU::$woke === false) ? "unser_no_wakeup OK\n" : "unser_no_wakeup FAIL\n";

// --- CR-002: a denied SLOTS class must NOT be autoloaded. The filter exists
// to prevent instantiating (and here, even loading) attacker-named classes.
// Craft a SLOTS frame naming a class that is not defined, register a recording
// autoloader, decode with allowed_classes disabled, and assert the autoloader
// was never consulted. ---
$GLOBALS['autoload_hits'] = [];
spl_autoload_register(function ($name) {
    $GLOBALS['autoload_hits'][] = $name;  // record only; never define
});
// [0x02][ndict=1][len=13]"AutoloadProbe"[0x12][class_idx=0][nprops=0]
$deny = "\x02\x01\x0dAutoloadProbe\x12\x00\x00";
$r = phpser_unserialize($deny, ['allowed_classes' => false]);
$hit = false;
foreach ($GLOBALS['autoload_hits'] as $n) {
    if (strcasecmp($n, 'AutoloadProbe') === 0) { $hit = true; break; }
}
echo (!$hit) ? "deny_no_autoload OK\n" : "deny_no_autoload FAIL\n";
echo ($r instanceof __PHP_Incomplete_Class) ? "deny_incomplete OK\n"
    : "deny_incomplete FAIL " . var_export($r, true) . "\n";

// --- CR-010.2b: a SLOTS frame planting a wrong-typed value into a declared
// typed slot must fail-fast (TypeError), not corrupt the object or crash. ---
class Typed { public int $n = 0; }
// [0x02][ndict=1][len=5]"Typed"[0x12][class_idx=0][nprops=1][0x00 NULL] → null into int
$bad_typed = "\x02\x01\x05Typed\x12\x00\x01\x00";
try {
    phpser_unserialize($bad_typed);
    echo "typed_reject FAIL (no throw)\n";
} catch (\TypeError $e) {
    echo "typed_reject OK\n";
}

// --- CR-010.3a: ASSOC_DICT with duplicate dict-key indices must collapse to
// last-write-wins on the untrusted path (zend_symtable_update), not create a
// phantom bucket. ---
// [0x02][ndict=1][len=1]"k"[0x13 ASSOC_DICT][n=2][idx=0][idx=0][0x03 zz(1)=2][0x03 zz(2)=4]
$dup = "\x02\x01\x01k\x13\x02\x00\x00\x03\x02\x03\x04";
$rd = phpser_unserialize($dup);
echo (is_array($rd) && count($rd) === 1 && $rd['k'] === 2) ? "assocdict_dup OK\n"
    : "assocdict_dup FAIL " . var_export($rd, true) . "\n";

// --- CR-010.3b: ASSOC_DICT with a numeric-string dict key must coerce to an
// integer array key, matching PHP array semantics. ---
// [0x02][ndict=1][len=1]"7"[0x13][n=1][idx=0][0x03 zz(99)=198 → 0xC6 0x01]
$num = "\x02\x01\x017\x13\x01\x00\x03\xc6\x01";
$rn = phpser_unserialize($num);
$keys = is_array($rn) ? array_keys($rn) : [];
echo (is_array($rn) && count($keys) === 1 && $keys[0] === 7 && $rn[7] === 99)
    ? "assocdict_numkey OK\n"
    : "assocdict_numkey FAIL " . var_export($rn, true) . "\n";

// --- CR-010.2a residual: an eligible typed DTO must actually take the SLOTS
// path (tag 0x12), so 073's allowed_classes coverage can't silently slide onto
// the keyed TAG_OBJECT path. Assert the emitted wire byte. ---
class Dto { public int $a = 1; public string $b = "x"; }
$enc = phpser_serialize(new Dto());
// Parse [version][varint ndict][ndict×(varint len, bytes)][value tag]. All the
// varints in this tiny payload are single-byte, so a byte walk suffices.
echo (ord($enc[0]) === 0x02) ? "dto_is_v2 OK\n" : "dto_is_v2 FAIL\n";
$pos = 1;
$ndict = ord($enc[$pos++]);
for ($i = 0; $i < $ndict; $i++) {
    $len = ord($enc[$pos++]);
    $pos += $len;
}
echo (ord($enc[$pos]) === 0x12) ? "dto_slots_tag OK\n"
    : "dto_slots_tag FAIL (got " . dechex(ord($enc[$pos])) . ")\n";

// --- CR-010: the TAG_OBJECT_SLOTS nprops integrity gate. An older prefix is
// accepted so properties appended to the effective slot table keep their class
// defaults; a newer payload with more slots than this class knows still rejects.
// (087's historical "bad_slots" craft rejected at dict parsing instead, never
// reaching this check.) Real class + correct dict here. ---
class Dto2 { public int $a = 0; public string $b = ""; }  // slot_count = 2
$np_low  = "\x02\x01\x04Dto2\x12\x00\x01\x03\x02";                  // nprops=1 (older prefix)
$np_high = "\x02\x01\x04Dto2\x12\x00\x03\x03\x02\x0c\x01x\x03\x02"; // nprops=3 (too many)
$np_ok   = "\x02\x01\x04Dto2\x12\x00\x02\x03\x02\x0c\x01x";         // nprops=2 (exact)
$np_l = phpser_unserialize($np_low);
echo ($np_l instanceof Dto2 && $np_l->a === 1 && $np_l->b === "")
    ? "nprops_low OK\n" : "nprops_low FAIL\n";
echo (phpser_unserialize($np_high) === null) ? "nprops_high OK\n" : "nprops_high FAIL\n";
$np_c = phpser_unserialize($np_ok);
echo ($np_c instanceof Dto2 && $np_c->a === 1 && $np_c->b === "x")
    ? "nprops_ok OK\n" : "nprops_ok FAIL " . var_export($np_c, true) . "\n";

// --- CR-008: signed decode of a body that fails to decode (valid HMAC, corrupt
// frame) must THROW, not return a silent null a caller can't tell from a
// legitimately-signed null. A real signed null still decodes cleanly. ---
$key = "cr008-key";
$signed_null = phpser_serialize_signed(null, $key);
$ok = false;
try { $ok = (phpser_unserialize_signed($signed_null, $key) === null); }
catch (\Throwable $e) { $ok = false; }
echo $ok ? "signed_null_ok OK\n" : "signed_null_ok FAIL\n";
// Forge valid HMAC over an undecodable frame (TAG_OBJECT with an OOB class idx).
$bad_frame = "\x01\x00\x0a\x00";
$forged = $bad_frame . hash_hmac("sha256", $bad_frame, $key, true);
try {
    phpser_unserialize_signed($forged, $key);
    echo "signed_corrupt FAIL (no throw)\n";
} catch (\Exception $e) {
    echo (strpos($e->getMessage(), "failed to decode") !== false)
        ? "signed_corrupt OK\n" : "signed_corrupt FAIL (" . $e->getMessage() . ")\n";
}
?>
--EXPECT--
unser_type OK
unser_called OK
unser_no_wakeup OK
deny_no_autoload OK
deny_incomplete OK
typed_reject OK
assocdict_dup OK
assocdict_numkey OK
dto_is_v2 OK
dto_slots_tag OK
nprops_low OK
nprops_high OK
nprops_ok OK
signed_null_ok OK
signed_corrupt OK
