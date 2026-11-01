--TEST--
phpser: unknown object classes call unserialize_callback_func like native unserialize()
--EXTENSIONS--
phpser
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
$e = function (string $s): string { return chr(strlen($s)) . $s; };

$object = fn (string $c) => "\x01" . "\x02" . $e($c) . $e("v")
    . "\x0a\x00\x01" . "\x01" . "\x03\x02";
$magic = fn (string $c) => "\x01" . "\x02" . $e($c) . $e("k")
    . "\x0e\x00" . "\x06\x01" . "\x02\x01k" . "\x03\x04";
$slots = fn (string $c) => "\x02" . "\x01" . $e($c)
    . "\x12\x00\x01" . "\x03\x06";

class LegacySrc implements Serializable {
    public function serialize() { return "x"; }
    public function unserialize($data) {}
}
$legacy = fn (string $c) => str_replace("LegacySrc", $c, phpser_serialize(new LegacySrc()));

function thrower(string $c): void {
    echo "callback($c)\n";
    throw new DomainException("Class not found: $c");
}
function definer(string $c): void {
    echo "callback($c)\n";
    if ($c === 'LegacyDef') {
        eval("class $c implements Serializable { public function serialize() { return 'x'; } public function unserialize(\$d) { \$this->seen = \$d; } public \$seen; }");
    } else {
        eval("class $c { public \$v; public \$k; }");
    }
}
function noop(string $c): void {
    echo "callback($c)\n";
}

$cases = [
    'object' => [$object, 'GoneObjA', 'DefObjAA'],
    'magic'  => [$magic,  'GoneMagA', 'DefMagAA'],
    'slots'  => [$slots,  'GoneSloA', 'DefSloAA'],
    'legacy' => [$legacy, 'LegacyGon', 'LegacyDef'],
];

foreach ($cases as $tag => [$build, $gone, $def]) {
    echo "-- $tag\n";

    ini_set('unserialize_callback_func', 'thrower');
    try {
        phpser_unserialize($build($gone));
        echo "no exception\n";
    } catch (DomainException $ex) {
        echo get_class($ex), ": ", $ex->getMessage(), "\n";
    }
    try {
        phpser_unserialize_signed(phpser_serialize_signed_raw($build($gone)), 'k');
        echo "no exception\n";
    } catch (DomainException $ex) {
        echo "signed ", get_class($ex), ": ", $ex->getMessage(), "\n";
    }

    ini_set('unserialize_callback_func', 'definer');
    $v = phpser_unserialize($build($def));
    echo get_class($v), "\n";

    ini_set('unserialize_callback_func', 'noop');
    $v = phpser_unserialize($build($gone));
    echo get_debug_type($v), "\n";

    ini_set('unserialize_callback_func', 'thrower');
    $v = phpser_unserialize($build($gone), ['allowed_classes' => false]);
    echo get_debug_type($v), "\n";

    ini_set('unserialize_callback_func', '');
    $v = phpser_unserialize($build($gone));
    echo get_debug_type($v), "\n";
}

function phpser_serialize_signed_raw(string $frame): string {
    return $frame . hash_hmac('sha256', $frame, 'k', true);
}

echo "-- enum\n";
enum Src: string { case A = 'a'; }
ini_set('unserialize_callback_func', 'thrower');
$p = str_replace("Src", "Gon", phpser_serialize(Src::A));
var_dump(phpser_unserialize($p));

echo "-- native parity\n";
try {
    unserialize('O:8:"GoneObjA":0:{}');
} catch (DomainException $ex) {
    echo get_class($ex), ": ", $ex->getMessage(), "\n";
}
?>
--EXPECTF--
-- object
callback(GoneObjA)
DomainException: Class not found: GoneObjA
callback(GoneObjA)
signed DomainException: Class not found: GoneObjA
callback(DefObjAA)
DefObjAA
callback(GoneObjA)

Warning: phpser_unserialize(): Function noop() hasn't defined the class it was called for in %s on line %d
__PHP_Incomplete_Class
__PHP_Incomplete_Class
__PHP_Incomplete_Class
-- magic
callback(GoneMagA)
DomainException: Class not found: GoneMagA
callback(GoneMagA)
signed DomainException: Class not found: GoneMagA
callback(DefMagAA)
DefMagAA
callback(GoneMagA)

Warning: phpser_unserialize(): Function noop() hasn't defined the class it was called for in %s on line %d
__PHP_Incomplete_Class
__PHP_Incomplete_Class
__PHP_Incomplete_Class
-- slots
callback(GoneSloA)
DomainException: Class not found: GoneSloA
callback(GoneSloA)
signed DomainException: Class not found: GoneSloA
callback(DefSloAA)
DefSloAA
callback(GoneSloA)

Warning: phpser_unserialize(): Function noop() hasn't defined the class it was called for in %s on line %d
__PHP_Incomplete_Class
__PHP_Incomplete_Class
__PHP_Incomplete_Class
-- legacy
callback(LegacyGon)
DomainException: Class not found: LegacyGon
callback(LegacyGon)
signed DomainException: Class not found: LegacyGon
callback(LegacyDef)
LegacyDef
callback(LegacyGon)

Warning: phpser_unserialize(): Function noop() hasn't defined the class it was called for in %s on line %d
null
__PHP_Incomplete_Class
null
-- enum
NULL
-- native parity
callback(GoneObjA)
DomainException: Class not found: GoneObjA
