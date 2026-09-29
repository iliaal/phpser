--TEST--
phpser: shared-array back-references stay valid when serialization hooks mutate or free arrays mid-walk
--EXTENSIONS--
phpser
--FILE--
<?php

function rt($v) { return phpser_unserialize(phpser_serialize($v)); }
function nat($v) { return unserialize(serialize($v)); }

// 1. A hook appends to a shared array through a global alias between two
//    occurrences. COW separates the global, so both occurrences still hold
//    the pre-hook table, as in native.
class Mutator137 {
    public function __serialize(): array {
        $GLOBALS['shared'][] = 'added';
        return ['m' => 1];
    }
    public function __unserialize(array $d): void {}
}
$shared = ['a', 'b'];
$src = ['first' => [$shared], 'hook' => new Mutator137, 'second' => [$shared]];
$d = rt($src);
$shared = ['a', 'b'];
$src = ['first' => [$shared], 'hook' => new Mutator137, 'second' => [$shared]];
$n = nat($src);
echo ($d['first'] === $n['first'] && $d['second'] === $n['second']
      && $d['second'][0] === ['a', 'b'])
    ? "hook append OK\n" : "hook append FAIL\n";

// 2. ABA: a hook returns a temporary shared array, which is claimed and then
//    loses its last owner when the hook's return value is released. A later
//    hook allocates a same-sized shared array. The claim's pin keeps the first
//    table alive, so the second cannot inherit its back-reference.
class TempArr137 {
    public $data;
    public function __serialize(): array {
        $t = ['temp-' . random_int(1, 1), 'x'];
        return [$t, $t];
    }
    public function __unserialize(array $d): void { $this->data = $d; }
}
class LaterArr137 {
    public $data;
    public function __serialize(): array {
        // The allocator reuses freed tables LIFO; the pad takes the slot of
        // the previous return array so $u lands on the freed claimed table.
        $pad = [random_int(0, 0)];
        $u = ['late-' . random_int(2, 2), 'y'];
        return [$u, $u];
    }
    public function __unserialize(array $d): void { $this->data = $d; }
}
$src = [];
for ($i = 0; $i < 16; $i++) { $src[] = new TempArr137; $src[] = new LaterArr137; }
$d = rt($src);
$aba_ok = true;
foreach ($d as $i => $obj) {
    $want = ($i % 2) ? ['late-2', 'y'] : ['temp-1', 'x'];
    if ($obj->data !== [$want, $want]) { $aba_ok = false; break; }
}
echo $aba_ok ? "aba OK\n" : "aba FAIL\n";

// 3. __serialize returning a property array: the hook's return value is not
//    an owner, and a second object returning the same array still decodes
//    to equal content with object identity intact.
class Wrap137 {
    public $data;
    public function __construct($d) { $this->data = $d; }
    public function __serialize(): array { return $this->data; }
    public function __unserialize(array $d): void { $this->data = $d; }
}
$inner = new stdClass;
$payload_arr = ['obj' => $inner, 'list' => [1, 2, 3]];
$w1 = new Wrap137($payload_arr);
$w2 = new Wrap137($payload_arr);
$d = rt([$w1, $w2, $payload_arr]);
echo ($d[0]->data['obj'] === $d[1]->data['obj'] && $d[1]->data['obj'] === $d[2]['obj']
      && $d[0]->data['list'] === [1, 2, 3])
    ? "serialize return OK\n" : "serialize return FAIL\n";

// 4. __sleep and property snapshots do not count as sharing: an array held
//    by exactly one property emits no TAG_SHARED_ARRAY prefix.
class Sleeper137 {
    public $list;
    public function __construct() { $this->list = [random_int(1, 1), 2]; }
    public function __sleep() { return ['list']; }
}
final class Slots137 {
    public array $list;
    public ?object $o = null;
    public function __construct() { $this->list = [random_int(1, 1), 2]; $this->o = new stdClass; }
}
$p1 = phpser_serialize([new Sleeper137]);
$p2 = phpser_serialize([new Slots137]);
echo (strpos($p1, "\x18") === false && strpos($p2, "\x18") === false)
    ? "snapshot not shared OK\n" : "snapshot not shared FAIL\n";

// 5. __wakeup and __unserialize receive decoded shared arrays and may write
//    to them; each write separates, leaving the other holders intact.
class Waker137 {
    public $list;
    public function __wakeup() { $this->list[] = 'woke'; }
}
class Unser137 {
    public $got;
    public function __serialize(): array { return ['l' => $GLOBALS['shared_list']]; }
    public function __unserialize(array $d): void { $d['l'][] = 'u'; $this->got = $d['l']; }
}
$shared_list = ['s1', 's2'];
$w = new Waker137;
$w->list = $shared_list;
$d = rt(['w' => $w, 'u' => new Unser137, 'plain' => [$shared_list]]);
echo ($d['w']->list === ['s1', 's2', 'woke'] && $d['u']->got === ['s1', 's2', 'u']
      && $d['plain'][0] === ['s1', 's2'])
    ? "hooks separate OK\n" : "hooks separate FAIL\n";

// 6. A cycle collection started by the encoder's own releases (the 30000
//    children of a duplicated shared array overflow the root buffer) runs
//    destructors of unrelated garbage before any hook has run. Such a
//    destructor frees a claimed array through a reference alias and stores a
//    fresh array into a later reference, where the freed address is reused.
//    The later value must never decode as the freed array.
class GcCtl137 {
    public static bool $armed = false;
    public static bool $fired = false;
    public static function fire(): void {
        if (!self::$armed || self::$fired) return;
        self::$fired = true;
        unset($GLOBALS['gc_ext']);
        $GLOBALS['gc_alias'] = null;
        $n = ['new-' . random_int(1, 1), 'y'];
        $GLOBALS['gc_keep'] = $n;
        $GLOBALS['gc_alias2'] = $n;
    }
}
class GcJunk137 {
    public static int $dtors = 0;
    public $self;
    public function __destruct() { self::$dtors++; GcCtl137::fire(); }
}
$gc_alias = ['old-' . random_int(1, 1), 'z'];
$gc_ext = $gc_alias;
$gc_alias2 = null;
$gc_big = [];
for ($i = 0; $i < 30000; $i++) $gc_big[] = [$i];
$gc_big_keep = $gc_big;
$gc_payload = [&$gc_alias, $gc_big, &$gc_alias2];
gc_collect_cycles();
for ($i = 0; $i < 10; $i++) { $j = new GcJunk137; $j->self = $j; unset($j); }
GcCtl137::$armed = true;
$s = phpser_serialize($gc_payload);
GcCtl137::$armed = false;
$fired_during_encode = GcCtl137::$fired;
$d = phpser_unserialize($s);
$want = $fired_during_encode ? ['new-1', 'y'] : null;
echo ($d[2] === $want && $d[2] !== ['old-1', 'z']) ? "gc destructor aba OK\n" : "gc destructor aba FAIL\n";
gc_collect_cycles();
echo (gc_enabled() && GcJunk137::$dtors === 10) ? "gc resumed OK\n" : "gc resumed FAIL\n";
?>
--EXPECT--
hook append OK
aba OK
serialize return OK
snapshot not shared OK
hooks separate OK
gc destructor aba OK
gc resumed OK
