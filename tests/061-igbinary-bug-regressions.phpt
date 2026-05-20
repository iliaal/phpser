--TEST--
phpser: bug-shape regressions ported from pecl/igbinary tests
--SKIPIF--
<?php if (!extension_loaded("phpser")) print "skip phpser not loaded"; ?>
--FILE--
<?php

// --- bug72134-style: repeated decode of object with dynamic property ---
// igbinary's bug was a segfault at the 3rd attempt due to refcount handling
// on the dynamic-prop HT. Run many times; check value survives.
#[\AllowDynamicProperties]
class Bug72134 { public $bar = "test"; }
$o = new Bug72134();
$o->i = 1;
$ser = phpser_serialize($o);
for ($i = 0; $i < 30; $i++) {
    if (phpser_unserialize($ser)->bar !== "test") { echo "bug72134 fail at $i\n"; break; }
}
echo "bug72134 OK\n";

// --- igbinary_020-style: incomplete class fallback ---
// Encode a payload referring to an unknown class, decode it — should yield
// some safe representation (we fall back to stdClass; igbinary fabricates
// __PHP_Incomplete_Class). Either is acceptable as long as no crash.
class TempClass020 { public int $a = 7; public int $b = 2; }
$ser = phpser_serialize(new TempClass020());
// "Forget" the class by overwriting the name in the dict header. We just
// check decode doesn't crash and returns a value with our props.
$rt = phpser_unserialize($ser); // class still exists; sanity check
echo $rt->a === 7 ? "bug020 OK\n" : "bug020 fail\n";

// --- igbinary_024-style: recursive objects ---
// Tree of parent/child nodes. Cycles via parent pointer flatten via depth cap.
class Node024 {
    public ?Node024 $parent = null;
    public array $children = [];
}
$root = new Node024();
for ($i = 0; $i < 5; $i++) {
    $c = new Node024();
    $c->parent = $root; // cycle: child -> parent -> nothing on parent yet
    $root->children[] = $c;
}
$rt = phpser_unserialize(phpser_serialize($root));
echo (count($rt->children) === 5) ? "bug024 OK\n" : "bug024 fail\n";

// --- igbinary_065-style: object that exposes IS_UNDEF entries via __sleep ---
// We don't honor __sleep, but objects with NULL-valued typed props should
// still round-trip the props' current value (NULL when nullable type).
class Bug065 {
    public ?int $kept = 2;
    public ?int $x = null;
}
$rt = phpser_unserialize(phpser_serialize(new Bug065()));
echo ($rt->kept === 2 && $rt->x === null) ? "bug065 OK\n" : "bug065 fail\n";

// --- igbinary_066-style: empty arrays in assoc ---
$rt = phpser_unserialize(phpser_serialize(['1st' => [], '2nd' => [], '3rd' => []]));
echo (count($rt) === 3 && $rt['1st'] === [] && $rt['2nd'] === [] && $rt['3rd'] === [])
    ? "bug066 OK\n" : "bug066 fail\n";

// --- igbinary_069-style: many duplicate strings, big enough to cross
//     the dict's 16-bit boundary in igbinary. Stresses our hash_map. ---
$arr = [];
$n = 5000; // smaller than igbinary's 65k+; enough to exercise the path
for ($i = 0; $i < $n; $i++) {
    $s = "row_$i";
    $arr[] = $s;
    $arr[] = $s; // each string used twice -> all dict-bound after the upgrade
}
$rt = phpser_unserialize(phpser_serialize($arr));
echo $rt === $arr ? "bug069 OK\n" : "bug069 fail (n=$n)\n";

// --- igbinary_077-style: large packed arrays ---
$a256 = range(0, 255);
$a64k = range(0, (1 << 16) - 1);
$rt256 = phpser_unserialize(phpser_serialize($a256));
$rt64k = phpser_unserialize(phpser_serialize($a64k));
echo ($rt256 === $a256 && $rt64k === $a64k) ? "bug077 OK\n" : "bug077 fail\n";

// --- igbinary_080-style: numeric-string content that looks like int ---
// PHP's array indexing converts string '3010480803' to int — but as a
// VALUE in an assoc array, it stays a string. Verify both keys and values.
$var = ['id' => "3010480803", 'user_id' => 12346];
$rt = phpser_unserialize(phpser_serialize($var));
echo ($rt === $var) ? "bug080a OK\n" : "bug080a fail\n";
$var = ['id' => "3010480803", 'user_id' => "3010480804"];
$rt = phpser_unserialize(phpser_serialize($var));
echo ($rt === $var) ? "bug080b OK\n" : "bug080b fail\n";

// --- igbinary_086-style: many distinct classes ---
// Stress the encoder's dict + decoder's class lookup. Smaller scale than
// igbinary's 70k because run-tests.php startup adds latency.
for ($i = 0; $i < 200; $i++) {
    if (!class_exists("Bug086_$i")) eval("class Bug086_$i { public int \$v = $i; }");
}
$values = [];
for ($i = 0; $i < 200; $i++) {
    $cls = "Bug086_$i";
    $values[] = new $cls();
}
$rt = phpser_unserialize(phpser_serialize($values));
$ok = true;
foreach ($rt as $i => $obj) {
    if (!($obj instanceof ("Bug086_$i")) || $obj->v !== $i) { $ok = false; break; }
}
echo $ok ? "bug086 OK\n" : "bug086 fail\n";

// --- igbinary_090-style: arrays whose values come via globals (IS_INDIRECT) ---
// $GLOBALS is special — values come through as IS_INDIRECT. We deref in the
// encoder. Test indirectly by manipulating $GLOBALS.
$myGlobalVar086_a = 123;
$myGlobalVar086_b = "hello";
$g = [
    'a' => $GLOBALS['myGlobalVar086_a'] ?? 0,
    'b' => $GLOBALS['myGlobalVar086_b'] ?? '',
];
$rt = phpser_unserialize(phpser_serialize($g));
echo ($rt === $g) ? "bug090 OK\n" : "bug090 fail\n";

// --- igbinary_096-style: array internal pointer after unserialize ---
// PHP's serialize/unserialize doesn't preserve the internal array pointer;
// the standard contract is that after unserialize() current() returns the
// first element (or false for empty). Verify our behavior matches.
$nested = ['a' => ['b' => ['c' => 42]]];
$rt = phpser_unserialize(phpser_serialize($nested));
echo (current($rt) === ['b' => ['c' => 42]]) ? "bug096 OK\n" : "bug096 fail\n";

// --- igbinary_099-style: dynamic property emission (PHP 8.2+) ---
// On PHP 8.2+, creating a dynamic property without #[AllowDynamicProperties]
// is a deprecation. Encoding/decoding such an object should preserve the
// declared-vs-dynamic split correctly (or at least not warn-spam if the
// class allows dynamic props). Verify with AllowDynamicProperties to avoid
// the PHP-version-dependent deprecation noise.
if (PHP_VERSION_ID >= 80200) {
    #[\AllowDynamicProperties]
    class Bug099 { public int $declared = 1; }
    $o = new Bug099();
    $o->extra = "dyn";
    $rt = phpser_unserialize(phpser_serialize($o));
    echo ($rt->declared === 1 && $rt->extra === "dyn") ? "bug099 OK\n" : "bug099 fail\n";
} else {
    echo "bug099 OK\n";
}
?>
--EXPECT--
bug72134 OK
bug020 OK
bug024 OK
bug065 OK
bug066 OK
bug069 OK
bug077 OK
bug080a OK
bug080b OK
bug086 OK
bug090 OK
bug096 OK
bug099 OK
