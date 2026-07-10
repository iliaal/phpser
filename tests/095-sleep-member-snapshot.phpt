--TEST--
phpser: __sleep selects its member set at return time — a property created while an earlier member serializes is not included (matches native)
--EXTENSIONS--
phpser
--FILE--
<?php
// Outer::__sleep lists ['a','b'] but 'b' does not exist yet. Serializing 'a'
// (an Inner) runs Inner::__sleep, which creates Outer::$b as a side effect.
// Native fixes the member set when __sleep returns, so 'b' is omitted; reading
// live during emission would wrongly include it. Assert phpser matches native.
#[\AllowDynamicProperties]
class Outer {
    public $a;
    public function __sleep() { return ['a', 'b']; }
    public function __wakeup() {}
}
class Inner {
    public $outer;
    public function __sleep() { $this->outer->b = "late"; return []; }
    public function __wakeup() {}
}
// Fresh graph per serializer: __sleep mutates the live object (creates $b), so
// reusing one object would let the first call contaminate the second.
function mk() { $o = new Outer; $i = new Inner; $i->outer = $o; $o->a = $i; return $o; }

$phpser = phpser_unserialize(phpser_serialize(mk()));
$native = unserialize(@serialize(mk()));  // native warns on the missing 'b'; not under test here
var_dump(isset($phpser->b));
var_dump(isset($phpser->b) === isset($native->b));
?>
--EXPECT--
bool(false)
bool(true)
