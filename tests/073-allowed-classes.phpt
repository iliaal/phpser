--TEST--
phpser: unserialize allowed_classes option (mirrors PHP's unserialize 2nd arg)
--EXTENSIONS--
phpser
--INI--
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php

class Approved { public int $n = 1; }
class Rejected { public int $n = 2; }
class HasMagic {
    public string $tag = "";
    public function __serialize(): array { return ['tag' => $this->tag]; }
    public function __unserialize(array $d): void { $this->tag = $d['tag']; }
}
class HasLegacy implements Serializable {
    public string $data = "";
    public function serialize(): string { return $this->data; }
    public function unserialize(string $d): void { $this->data = $d; }
}

// --- Default (no options): all classes allowed. ---
$a = new Approved(); $a->n = 7;
$rt = phpser_unserialize(phpser_serialize($a));
echo ($rt instanceof Approved && $rt->n === 7) ? "default_all OK\n" : "default_all FAIL\n";

// --- allowed_classes => true: same as no filter. ---
$rt = phpser_unserialize(phpser_serialize($a), ['allowed_classes' => true]);
echo ($rt instanceof Approved && $rt->n === 7) ? "explicit_true OK\n" : "explicit_true FAIL\n";

// --- allowed_classes => false: every object becomes __PHP_Incomplete_Class
// with the original class name attached. ---
$rt = phpser_unserialize(phpser_serialize($a), ['allowed_classes' => false]);
$ok = $rt instanceof __PHP_Incomplete_Class
   && get_mangled_object_vars($rt)['__PHP_Incomplete_Class_Name'] === 'Approved';
echo $ok ? "deny_all OK\n" : "deny_all FAIL " . var_export($rt, true) . "\n";

// --- Properties still land on the incomplete class. ---
$vars = get_mangled_object_vars($rt);
echo (isset($vars['n']) && $vars['n'] === 7) ? "deny_props_preserved OK\n" : "deny_props_preserved FAIL\n";

// --- allowed_classes => [Approved::class]: Approved comes back, Rejected falls back. ---
$mixed = [new Approved(), new Rejected()];
$mixed[0]->n = 100;
$mixed[1]->n = 200;
$rt = phpser_unserialize(phpser_serialize($mixed), ['allowed_classes' => [Approved::class]]);
echo ($rt[0] instanceof Approved && $rt[0]->n === 100) ? "approved_passes OK\n" : "approved_passes FAIL\n";
echo ($rt[1] instanceof __PHP_Incomplete_Class) ? "rejected_to_incomplete OK\n" : "rejected_to_incomplete FAIL\n";
$v = get_mangled_object_vars($rt[1]);
echo ($v['__PHP_Incomplete_Class_Name'] === 'Rejected' && $v['n'] === 200)
    ? "rejected_props_preserved OK\n" : "rejected_props_preserved FAIL\n";

// --- Case-insensitive matching: PHP class names case-insensitive. ---
$rt = phpser_unserialize(phpser_serialize(new Approved()), ['allowed_classes' => ['APPROVED']]);
echo ($rt instanceof Approved) ? "case_insensitive OK\n" : "case_insensitive FAIL\n";

// --- Filter applies to __serialize/__unserialize classes (TAG_OBJECT_MAGIC). ---
$m = new HasMagic(); $m->tag = "abc";
$rt = phpser_unserialize(phpser_serialize($m), ['allowed_classes' => false]);
echo ($rt instanceof __PHP_Incomplete_Class
      && get_mangled_object_vars($rt)['__PHP_Incomplete_Class_Name'] === 'HasMagic')
    ? "magic_filtered OK\n" : "magic_filtered FAIL\n";

// --- Filter applies to Serializable classes (TAG_OBJECT_LEGACY). ---
$l = new HasLegacy(); $l->data = "xyz";
$rt = phpser_unserialize(phpser_serialize($l), ['allowed_classes' => false]);
echo ($rt instanceof __PHP_Incomplete_Class
      && get_mangled_object_vars($rt)['__PHP_Incomplete_Class_Name'] === 'HasLegacy')
    ? "legacy_filtered OK\n" : "legacy_filtered FAIL\n";

// --- Filter applies to enums (TAG_ENUM). ---
enum Color { case Red; case Blue; }
$rt = phpser_unserialize(phpser_serialize(Color::Red), ['allowed_classes' => false]);
echo ($rt instanceof __PHP_Incomplete_Class) ? "enum_filtered OK\n" : "enum_filtered FAIL\n";

// --- Enum filtered via an ARRAY allowlist that omits it (distinct code path
// from the `false` fast-path: array membership check on TAG_ENUM). ---
$rt = phpser_unserialize(phpser_serialize(Color::Blue), ['allowed_classes' => ['Approved']]);
echo ($rt instanceof __PHP_Incomplete_Class) ? "enum_array_denied OK\n" : "enum_array_denied FAIL\n";

// --- Enum allowed via an ARRAY allowlist that includes it comes back as the
// real case singleton. ---
$rt = phpser_unserialize(phpser_serialize(Color::Blue), ['allowed_classes' => [Color::class]]);
echo ($rt === Color::Blue) ? "enum_array_allowed OK\n" : "enum_array_allowed FAIL\n";

// --- Filter is recursive: nested object in __serialize data gets filtered too.
// Untyped slot — a typed slot would reject the incomplete class at assignment
// time (real-world allowed_classes pattern: don't use typed object props if
// you want incomplete-class fallback to survive). ---
class Container {
    public $inner = null;
    public function __serialize(): array { return ['inner' => $this->inner]; }
    public function __unserialize(array $d): void { $this->inner = $d['inner']; }
}
$c = new Container(); $c->inner = new Approved(); $c->inner->n = 33;
$rt = phpser_unserialize(phpser_serialize($c), ['allowed_classes' => [Container::class]]);
echo ($rt instanceof Container && $rt->inner instanceof __PHP_Incomplete_Class)
    ? "nested_filter OK\n" : "nested_filter FAIL\n";

// --- Invalid option type throws. ---
$threw = false;
try {
    phpser_unserialize(phpser_serialize($a), ['allowed_classes' => 42]);
} catch (ValueError $e) {
    $threw = true;
}
echo $threw ? "invalid_option OK\n" : "invalid_option FAIL\n";

// --- Empty array allowed_classes: same as false (no classes match). ---
$rt = phpser_unserialize(phpser_serialize($a), ['allowed_classes' => []]);
echo ($rt instanceof __PHP_Incomplete_Class) ? "empty_array OK\n" : "empty_array FAIL\n";

// --- Reference-wrapped allowlist entry (foreach-by-ref residue) derefs
// like native unserialize(). ---
$allow = ['Approved'];
foreach ($allow as &$cname) { $cname = trim($cname); }
try {
    $rt = phpser_unserialize(phpser_serialize($a), ['allowed_classes' => $allow]);
    echo ($rt instanceof Approved) ? "ref_entry OK\n" : "ref_entry FAIL\n";
} catch (Throwable $e) {
    echo "ref_entry FAIL ", get_class($e), ": ", $e->getMessage(), "\n";
}
unset($cname);

// --- Reference-wrapped option value: false by reference disables all
// classes, same as plain false. ---
$flag = false;
try {
    $rt = phpser_unserialize(phpser_serialize($a), ['allowed_classes' => &$flag]);
    echo ($rt instanceof __PHP_Incomplete_Class) ? "ref_false OK\n" : "ref_false FAIL\n";
} catch (Throwable $e) {
    echo "ref_false FAIL ", get_class($e), ": ", $e->getMessage(), "\n";
}

// --- Reference-wrapped option value: array by reference applies the
// allowlist, same as a plain array. ---
$list = ['Approved'];
try {
    $rt = phpser_unserialize(phpser_serialize($a), ['allowed_classes' => &$list]);
    echo ($rt instanceof Approved) ? "ref_array OK\n" : "ref_array FAIL\n";
} catch (Throwable $e) {
    echo "ref_array FAIL ", get_class($e), ": ", $e->getMessage(), "\n";
}

?>
--EXPECT--
default_all OK
explicit_true OK
deny_all OK
deny_props_preserved OK
approved_passes OK
rejected_to_incomplete OK
rejected_props_preserved OK
case_insensitive OK
magic_filtered OK
legacy_filtered OK
enum_filtered OK
enum_array_denied OK
enum_array_allowed OK
nested_filter OK
invalid_option OK
empty_array OK
ref_entry OK
ref_false OK
ref_array OK
