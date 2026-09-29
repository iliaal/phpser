--TEST--
phpser: __sleep name resolution, omissions, and warnings match native serialize()
--EXTENSIONS--
phpser
--FILE--
<?php
class SleepParent {
    private $secret = 's';
    protected $prot = 'p';
    public $pub = 'u';
    private $shadow = 'parent-shadow';
}
#[AllowDynamicProperties]
class SleepChild extends SleepParent {
    private $own = 'o';
    private $shadow = 'child-shadow';
    public static $stat = 'static';
    public $untyped = 'x';
    public int $typed;
    public int $typedSet = 3;
    public static array $names = [];
    public function __sleep(): array { return self::$names; }
}
/* A dynamic key spelled like a private of the child sits beside the
 * inherited protected; native's second candidate selects the dynamic one. */
class SleepProtBase { protected $p = 'declared-prot'; }
#[AllowDynamicProperties]
class SleepProtChild extends SleepProtBase {
    public function __sleep(): array { return ['p']; }
}
function mk_mangled_dynamic(): SleepProtChild {
    $o = new SleepProtChild;
    /* 8.5 deprecates an object as ArrayObject storage; it is still the only
     * way to write a NUL-prefixed property name. */
    $ao = @new ArrayObject($o);
    $ao["\0SleepProtChild\0p"] = 'dyn-mangled';
    return $o;
}
/* No dynamic properties, so no properties table exists before __sleep. */
class SleepPlainChild extends SleepParent {
    public function __sleep(): array { return ['secret', 'prot', 'pub', 'nope', 'pub']; }
}

function capture(callable $f): array {
    $w = [];
    set_error_handler(function (int $no, string $msg) use (&$w): bool {
        $w[] = preg_replace('/^\w+\(\): /', '', $msg);
        return true;
    });
    try { $r = $f(); } finally { restore_error_handler(); }
    return [$r, $w];
}

/* With dynamic properties the table holds more than the declared slots;
 * without them it mirrors the slots. Both layouts must resolve alike. */
function mk(): SleepChild {
    $o = new SleepChild;
    unset($o->untyped);
    $o->dyn = 'd';
    $o->{'123'} = 'num';
    return $o;
}
function mk_declared(): SleepChild {
    $o = new SleepChild;
    unset($o->untyped);
    return $o;
}

function check(string $label, array $names, ?callable $mk = null): void {
    SleepChild::$names = $names;
    $mk ??= mk(...);
    /* Build outside capture() so only serializer diagnostics are compared. */
    $po = $mk();
    $no = $mk();
    [$p, $pw] = capture(fn() => phpser_serialize($po));
    [$n, $nw] = capture(fn() => serialize($no));
    $rt = serialize(phpser_unserialize($p, ['allowed_classes' => false]));
    echo $label, ": bytes ", $rt === $n ? "SAME" : "DIFF", ", warnings ",
        $pw === $nw ? "SAME" : "DIFF", "\n";
    echo "  native ", str_replace("\0", '\0', $n), "\n";
    foreach ($nw as $m) echo "  warn ", str_replace("\0", '\0', $m), "\n";
    if ($rt !== $n) echo "  phpser ", str_replace("\0", '\0', $rt), "\n";
    if ($pw !== $nw) foreach ($pw as $m) echo "  phpser warn ", str_replace("\0", '\0', $m), "\n";
}

check("parent private", ['secret', 'pub']);
check("parent private (declared only)", ['secret', 'pub'], mk_declared(...));
check("parent private mangled", ["\0SleepParent\0secret", 'pub']);
check("parent private mangled (declared only)", ["\0SleepParent\0secret", 'pub'], mk_declared(...));
check("shadowed private", ['shadow', "\0SleepParent\0shadow"]);
check("shadowed private (declared only)", ['shadow', "\0SleepParent\0shadow"], mk_declared(...));
check("protected", ['prot', "\0*\0prot"]);
check("protected (declared only)", ['prot', "\0*\0prot"], mk_declared(...));
check("missing", ['nope', 'own', 'also_nope']);
check("missing (declared only)", ['nope', 'own', 'also_nope'], mk_declared(...));
check("static", ['stat', 'pub']);
check("static (declared only)", ['stat', 'pub'], mk_declared(...));
check("duplicate", ['pub', 'own', 'pub', 'own']);
check("duplicate (declared only)", ['pub', 'own', 'pub', 'own'], mk_declared(...));
check("unset untyped", ['untyped', 'pub']);
check("unset untyped (declared only)", ['untyped', 'pub'], mk_declared(...));
check("uninitialized typed", ['typed', 'typedSet', 'typed']);
check("uninitialized typed (declared only)", ['typed', 'typedSet', 'typed'], mk_declared(...));
check("dynamic", ['dyn', 'pub']);
check("dynamic (declared only)", ['dyn', 'pub'], mk_declared(...));
check("integer name", [123, 'pub']);
check("integer name (declared only)", [123, 'pub'], mk_declared(...));
check("empty", []);
check("empty (declared only)", [], mk_declared(...));
check("no properties table", [], fn() => new SleepPlainChild);
check("mangled dynamic beside protected", [], mk_mangled_dynamic(...));
check("nested", [], fn() => [new SleepPlainChild, new SleepPlainChild]);
?>
--EXPECT--
parent private: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "secret" returned as member variable from __sleep() but does not exist
parent private (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "secret" returned as member variable from __sleep() but does not exist
parent private mangled: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:19:"\0SleepParent\0secret";s:1:"s";s:3:"pub";s:1:"u";}
parent private mangled (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:19:"\0SleepParent\0secret";s:1:"s";s:3:"pub";s:1:"u";}
shadowed private: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:18:"\0SleepChild\0shadow";s:12:"child-shadow";s:19:"\0SleepParent\0shadow";s:13:"parent-shadow";}
shadowed private (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:18:"\0SleepChild\0shadow";s:12:"child-shadow";s:19:"\0SleepParent\0shadow";s:13:"parent-shadow";}
protected: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:7:"\0*\0prot";s:1:"p";}
  warn "" is returned from __sleep() multiple times
protected (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:7:"\0*\0prot";s:1:"p";}
  warn "" is returned from __sleep() multiple times
missing: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:15:"\0SleepChild\0own";s:1:"o";}
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "also_nope" returned as member variable from __sleep() but does not exist
missing (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:15:"\0SleepChild\0own";s:1:"o";}
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "also_nope" returned as member variable from __sleep() but does not exist
static: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "stat" returned as member variable from __sleep() but does not exist
static (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "stat" returned as member variable from __sleep() but does not exist
duplicate: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"pub";s:1:"u";s:15:"\0SleepChild\0own";s:1:"o";}
  warn "pub" is returned from __sleep() multiple times
  warn "own" is returned from __sleep() multiple times
duplicate (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"pub";s:1:"u";s:15:"\0SleepChild\0own";s:1:"o";}
  warn "pub" is returned from __sleep() multiple times
  warn "own" is returned from __sleep() multiple times
unset untyped: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "untyped" returned as member variable from __sleep() but does not exist
unset untyped (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "untyped" returned as member variable from __sleep() but does not exist
uninitialized typed: bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:8:"typedSet";i:3;}
uninitialized typed (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:8:"typedSet";i:3;}
dynamic: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"dyn";s:1:"d";s:3:"pub";s:1:"u";}
dynamic (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn "dyn" returned as member variable from __sleep() but does not exist
integer name: bytes SAME, warnings SAME
  native O:10:"SleepChild":2:{s:3:"123";s:3:"num";s:3:"pub";s:1:"u";}
  warn SleepChild::__sleep() should return an array only containing the names of instance-variables to serialize
integer name (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":1:{s:3:"pub";s:1:"u";}
  warn SleepChild::__sleep() should return an array only containing the names of instance-variables to serialize
  warn "123" returned as member variable from __sleep() but does not exist
empty: bytes SAME, warnings SAME
  native O:10:"SleepChild":0:{}
empty (declared only): bytes SAME, warnings SAME
  native O:10:"SleepChild":0:{}
no properties table: bytes SAME, warnings SAME
  native O:15:"SleepPlainChild":2:{s:7:"\0*\0prot";s:1:"p";s:3:"pub";s:1:"u";}
  warn "secret" returned as member variable from __sleep() but does not exist
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "pub" is returned from __sleep() multiple times
mangled dynamic beside protected: bytes SAME, warnings SAME
  native O:14:"SleepProtChild":1:{s:17:"\0SleepProtChild\0p";s:11:"dyn-mangled";}
nested: bytes SAME, warnings SAME
  native a:2:{i:0;O:15:"SleepPlainChild":2:{s:7:"\0*\0prot";s:1:"p";s:3:"pub";s:1:"u";}i:1;O:15:"SleepPlainChild":2:{s:7:"\0*\0prot";s:1:"p";s:3:"pub";s:1:"u";}}
  warn "secret" returned as member variable from __sleep() but does not exist
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "pub" is returned from __sleep() multiple times
  warn "secret" returned as member variable from __sleep() but does not exist
  warn "nope" returned as member variable from __sleep() but does not exist
  warn "pub" is returned from __sleep() multiple times
