--TEST--
phpser: a cycle dropped mid-decode stays collectable after a GC run during decode
--EXTENSIONS--
phpser
--INI--
zend.enable_gc=1
--FILE--
<?php
// Each payload decodes an A<->B cycle, then drops it on a success path
// (duplicate key, discarded slot value, reserved incomplete-class key). A later
// object of the never-defined class Trig autoloads, and the autoloader runs the
// cycle collector while the id-table pins still hold the cycle. That run clears
// the root the drop recorded, so the pin release must record it again or the
// cycle leaks until request shutdown.
$destroyed = [];
class A { public $b; public function __destruct() { $GLOBALS['destroyed'][] = 'A'; } }
class B { public $a; public function __destruct() { $GLOBALS['destroyed'][] = 'B'; } }
class Holder { public $p; public $z; }
class TypedHolder { public mixed $p; public mixed $z; }
#[AllowDynamicProperties]
class DynHolder { public $p; public $z; }

spl_autoload_register(function ($c) {
    if ($c === 'Trig') gc_collect_cycles();
});

function vi(int $n): string {
    $s = '';
    while ($n >= 0x80) { $s .= chr(($n & 0x7f) | 0x80); $n >>= 7; }
    return $s . chr($n);
}

// Dict slots shared by every payload.
const DICT = ['p', 'A', 'b', 'B', 'a', 'z', 'Trig', 'Holder', 'TypedHolder',
              'stdClass', 'Gone', '__PHP_Incomplete_Class_Name', 'Denied',
              'x', 'DynHolder'];
function d(string $s): string { return vi(array_search($s, DICT, true)); }

function frame(string $body): string {
    $s = "\x01" . vi(count(DICT));
    foreach (DICT as $e) $s .= vi(strlen($e)) . $e;
    return $s . $body;
}

// A(b: B(a: <ref to A>)); A claims id $a_id.
function cycle(int $a_id): string {
    return "\x0a" . d('A') . "\x01" . d('b')
         . "\x0a" . d('B') . "\x01" . d('a') . "\x10" . vi($a_id);
}
$long1 = "\x03\x02";
$trig = "\x0a" . d('Trig') . "\x00";

$payloads = [
    // TAG_ASSOC duplicate key.
    'assoc_dup' => "\x06\x03"
        . "\x01" . d('p') . cycle(0)
        . "\x01" . d('p') . $long1
        . "\x01" . d('z') . $trig,
    // Declared untyped slot written twice.
    'slot_dup' => "\x0a" . d('Holder') . "\x03"
        . d('p') . cycle(1)
        . d('p') . $long1
        . d('z') . $trig,
    // Declared typed slot written twice.
    'typed_slot_dup' => "\x0a" . d('TypedHolder') . "\x03"
        . d('p') . cycle(1)
        . d('p') . $long1
        . d('z') . $trig,
    // A leading dynamic key materializes the property table, so the declared
    // slot's second write goes through its IS_INDIRECT entry.
    'indirect_slot_dup' => "\x0a" . d('DynHolder') . "\x04"
        . d('x') . $long1
        . d('p') . cycle(1)
        . d('p') . $long1
        . d('z') . $trig,
    // Dynamic property written twice.
    'dynamic_dup' => "\x0a" . d('stdClass') . "\x03"
        . d('p') . cycle(1)
        . d('p') . $long1
        . d('z') . $trig,
    // TAG_OBJECT_SLOTS for an unknown class discards its values.
    'slots_unknown' => "\x06\x02"
        . "\x01" . d('p') . "\x12" . d('Gone') . "\x01" . cycle(1)
        . "\x01" . d('z') . $trig,
    // Incomplete object: the reserved class-name key is discarded.
    'incomplete_reserved' => "\x06\x02"
        . "\x01" . d('p') . "\x0a" . d('Gone') . "\x01"
            . d('__PHP_Incomplete_Class_Name') . cycle(1)
        . "\x01" . d('z') . $trig,
];

function check(string $name, callable $decode): void {
    $GLOBALS['destroyed'] = [];
    $r = $decode();
    $n = gc_collect_cycles();
    $log = $GLOBALS['destroyed'];
    sort($log);
    printf("%s: %s, collected %d, destroyed [%s]\n",
        $name, gettype($r), $n, implode(',', $log));
    unset($r);
    gc_collect_cycles();
}

foreach ($payloads as $name => $body) {
    check($name, fn() => phpser_unserialize(frame($body)));
}

// Denied TAG_OBJECT_SLOTS class without a resident schema discards its values.
$denied = "\x06\x02"
    . "\x01" . d('p') . "\x12" . d('Denied') . "\x01" . cycle(1)
    . "\x01" . d('z') . $trig;
check('slots_denied', fn() => phpser_unserialize(frame($denied),
    ['allowed_classes' => ['Trig', 'A', 'B']]));

// Signed frames take the same teardown.
$key = str_repeat("s", 32);
$f = frame($payloads['assoc_dup']);
check('signed_assoc_dup',
    fn() => phpser_unserialize_signed($f . hash_hmac('sha256', $f, $key, true), $key));
echo "done\n";
?>
--EXPECT--
assoc_dup: array, collected 2, destroyed [A,B]
slot_dup: object, collected 2, destroyed [A,B]
typed_slot_dup: object, collected 2, destroyed [A,B]
indirect_slot_dup: object, collected 2, destroyed [A,B]
dynamic_dup: object, collected 2, destroyed [A,B]
slots_unknown: array, collected 2, destroyed [A,B]
incomplete_reserved: array, collected 2, destroyed [A,B]
slots_denied: array, collected 2, destroyed [A,B]
signed_assoc_dup: array, collected 2, destroyed [A,B]
done
