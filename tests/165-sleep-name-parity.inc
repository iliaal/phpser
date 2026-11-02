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
check("missing with NUL", ["\0SleepParent\0nope", "no\0pe", 'pub']);
check("missing with NUL (declared only)", ["\0SleepParent\0nope", "no\0pe", 'pub'], mk_declared(...));
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
