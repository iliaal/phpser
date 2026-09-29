--TEST--
phpser: lazy ghosts and proxies serialize their initialized state with and without __sleep, like native
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) die("skip requires PHP 8.4+ (lazy objects, property hooks)");
?>
--FILE--
<?php
class SleepLazy {
    public int $a = 0;
    protected string $p = 'p0';
    private ?array $q = null;
    public function __sleep(): array { return ['a', 'p', 'q']; }
    public function fill(int $a): static { $this->a = $a; $this->p = "p$a"; $this->q = [$a]; return $this; }
}
class PlainLazy {
    public int $a = 0;
    protected string $p = 'p0';
    private ?array $q = null;
    public function fill(int $a): static { $this->a = $a; $this->p = "p$a"; $this->q = [$a]; return $this; }
}
class SleepTouches {
    public int $a = 0;
    public int $b = 0;
    public function __sleep(): array { $this->b++; return ['a', 'b']; }
}
class SleepHooked {
    public int $raw = 1;
    public int $virt { get => 42; }
    public int $backed = 5 { get => $this->backed * 10; }
    public function __sleep(): array { return ['raw', 'virt', 'backed']; }
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

/* Serialize a fresh object with each serializer: native initializes the lazy
 * object, so sharing one instance would hide the phpser result. */
function check(string $label, callable $mk): void {
    $inits = 0;
    $po = $mk($inits);
    [$p, $pw] = capture(fn() => phpser_serialize($po));
    $p_inits = $inits;
    $p_lazy = (new ReflectionClass($po))->isUninitializedLazyObject($po);

    $inits = 0;
    $no = $mk($inits);
    [$n, $nw] = capture(fn() => serialize($no));
    $n_lazy = (new ReflectionClass($no))->isUninitializedLazyObject($no);

    $rt = serialize(phpser_unserialize($p, ['allowed_classes' => false]));
    echo $label, ": bytes ", $rt === $n ? "SAME" : "DIFF", ", warnings ",
        $pw === $nw ? "SAME" : "DIFF", ", inits ", $p_inits === $inits ? "SAME" : "DIFF",
        ", lazy-after ", $p_lazy === $n_lazy ? "SAME" : "DIFF", "\n";
    echo "  native ", str_replace("\0", '\0', $n), "\n";
    foreach ($nw as $m) echo "  warn ", $m, "\n";
    if ($rt !== $n) echo "  phpser ", str_replace("\0", '\0', $rt), "\n";
    if ($pw !== $nw) foreach ($pw as $m) echo "  phpser warn ", $m, "\n";
}

foreach ([SleepLazy::class, PlainLazy::class] as $cls) {
    $rc = new ReflectionClass($cls);
    check("$cls ghost", fn(&$i) => $rc->newLazyGhost(function ($o) use (&$i) { $i++; $o->fill(1); }));
    check("$cls proxy", fn(&$i) => $rc->newLazyProxy(function () use (&$i, $cls) { $i++; return (new $cls)->fill(2); }));
    check("$cls ghost skip-init", fn(&$i) => $rc->newLazyGhost(
        function ($o) use (&$i) { $i++; $o->fill(3); },
        ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE));
    check("$cls proxy skip-init", fn(&$i) => $rc->newLazyProxy(
        function () use (&$i, $cls) { $i++; return (new $cls)->fill(4); },
        ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE));
    check("$cls proxy initialized", function (&$i) use ($rc, $cls) {
        $o = $rc->newLazyProxy(function () use (&$i, $cls) { $i++; return (new $cls)->fill(5); });
        $rc->initializeLazyObject($o);
        return $o;
    });
}

$rc = new ReflectionClass(SleepTouches::class);
check("SleepTouches ghost", fn(&$i) => $rc->newLazyGhost(function ($o) use (&$i) { $i++; $o->a = 7; }));
check("SleepTouches proxy", fn(&$i) => $rc->newLazyProxy(function () use (&$i) {
    $i++; $x = new SleepTouches; $x->a = 8; return $x; }));

check("SleepHooked", fn(&$i) => new SleepHooked);

$rc = new ReflectionClass(SleepLazy::class);
$ghost = $rc->newLazyGhost(function ($o) { throw new RuntimeException("init boom"); });
try {
    phpser_serialize($ghost);
    echo "init throw: no exception\n";
} catch (RuntimeException $e) {
    echo "init throw: ", $e->getMessage(), "\n";
}

/* SKIP_INITIALIZATION_ON_SERIALIZE proxy whose real instance was reset to a
 * lazy ghost: native reads the ghost's uninitialized table and leaves it lazy. */
class SleepSkipReset {
    public $a = 0;
    public $b = 0;
    public function __sleep(): array { return ['a', 'b']; }
}
function skip_reset(string $fn): array {
    $rc = new ReflectionClass(SleepSkipReset::class);
    $inst = null;
    $inits = 0;
    $proxy = $rc->newLazyProxy(function () use (&$inst) {
        $inst = new SleepSkipReset; $inst->a = 1; $inst->b = 2; return $inst;
    }, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
    $proxy->a;
    $rc->resetAsLazyGhost($inst, function ($o) use (&$inits) { $inits++; $o->a = 10; $o->b = 20; });
    [$out, $w] = capture(fn() => $fn($proxy));
    if ($fn === 'phpser_serialize') {
        $out = serialize(phpser_unserialize($out, ['allowed_classes' => false]));
    }
    return [$out, $w, $inits, $rc->isUninitializedLazyObject($inst)];
}
[$po, $pw, $pi, $pl] = skip_reset('phpser_serialize');
[$no, $nw, $ni, $nl] = skip_reset('serialize');
echo "skip-init reset instance: bytes ", $po === $no ? "SAME" : "DIFF", ", warnings ",
    $pw === $nw ? "SAME" : "DIFF", ", inits ", $pi === $ni ? "SAME" : "DIFF",
    ", instance lazy-after ", $pl === $nl ? "SAME" : "DIFF", "\n";
echo "  native ", $no, " inits=", $ni, " lazy=", var_export($nl, true), "\n";
foreach ($nw as $m) echo "  warn ", $m, "\n";
if ($po !== $no || $pi !== $ni || $pl !== $nl) {
    echo "  phpser ", $po, " inits=", $pi, " lazy=", var_export($pl, true), "\n";
}
if ($pw !== $nw) foreach ($pw as $m) echo "  phpser warn ", $m, "\n";

/* The "missing" warning's handler resets the initialized proxy, dropping the
 * proxy's only reference to its real instance while the selected members
 * still point into that instance's slots. Native serialize() crashes here, so
 * this row has no native comparison. */
class SleepReset {
    public $a;
    public $b;
    public function __construct() { $this->a = str_repeat('A', 40); $this->b = str_repeat('B', 40); }
    public function __sleep(): array { return ['missing', 'a', 'b']; }
}
$rc = new ReflectionClass(SleepReset::class);
$proxy = $rc->newLazyProxy(fn($o) => new SleepReset);
$proxy->a;
$resets = 0;
set_error_handler(function (int $no, string $msg) use ($rc, $proxy, &$resets): bool {
    if ($resets++ === 0) {
        $rc->resetAsLazyProxy($proxy, fn($o) => new SleepReset);
        $junk = [];
        for ($i = 0; $i < 100; $i++) $junk[] = str_repeat('Z', 40);
    }
    return true;
});
$payload = phpser_serialize($proxy);
restore_error_handler();
echo "proxy reset in handler: ", serialize(phpser_unserialize($payload, ['allowed_classes' => false])), "\n";
?>
--EXPECT--
SleepLazy ghost: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"SleepLazy":3:{s:1:"a";i:1;s:4:"\0*\0p";s:2:"p1";s:12:"\0SleepLazy\0q";a:1:{i:0;i:1;}}
SleepLazy proxy: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"SleepLazy":3:{s:1:"a";i:2;s:4:"\0*\0p";s:2:"p2";s:12:"\0SleepLazy\0q";a:1:{i:0;i:2;}}
SleepLazy ghost skip-init: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"SleepLazy":0:{}
SleepLazy proxy skip-init: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"SleepLazy":0:{}
SleepLazy proxy initialized: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"SleepLazy":3:{s:1:"a";i:5;s:4:"\0*\0p";s:2:"p5";s:12:"\0SleepLazy\0q";a:1:{i:0;i:5;}}
PlainLazy ghost: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"PlainLazy":3:{s:1:"a";i:1;s:4:"\0*\0p";s:2:"p1";s:12:"\0PlainLazy\0q";a:1:{i:0;i:1;}}
PlainLazy proxy: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"PlainLazy":3:{s:1:"a";i:2;s:4:"\0*\0p";s:2:"p2";s:12:"\0PlainLazy\0q";a:1:{i:0;i:2;}}
PlainLazy ghost skip-init: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"PlainLazy":0:{}
PlainLazy proxy skip-init: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"PlainLazy":0:{}
PlainLazy proxy initialized: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:9:"PlainLazy":3:{s:1:"a";i:5;s:4:"\0*\0p";s:2:"p5";s:12:"\0PlainLazy\0q";a:1:{i:0;i:5;}}
SleepTouches ghost: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:12:"SleepTouches":2:{s:1:"a";i:7;s:1:"b";i:1;}
SleepTouches proxy: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:12:"SleepTouches":2:{s:1:"a";i:8;s:1:"b";i:1;}
SleepHooked: bytes SAME, warnings SAME, inits SAME, lazy-after SAME
  native O:11:"SleepHooked":2:{s:3:"raw";i:1;s:6:"backed";i:5;}
  warn "virt" returned as member variable from __sleep() but does not exist
init throw: init boom
skip-init reset instance: bytes SAME, warnings SAME, inits SAME, instance lazy-after SAME
  native O:14:"SleepSkipReset":0:{} inits=0 lazy=true
  warn "a" returned as member variable from __sleep() but does not exist
  warn "b" returned as member variable from __sleep() but does not exist
proxy reset in handler: O:10:"SleepReset":2:{s:1:"a";s:40:"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA";s:1:"b";s:40:"BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB";}
