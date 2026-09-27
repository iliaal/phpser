--TEST--
phpser: repairing snapshot references does not collect cycles before a serialization hook
--SKIPIF--
<?php
if (!extension_loaded('phpser')) die('skip phpser not loaded');
if (!array_key_exists('threshold', gc_status())) die('skip GC threshold unavailable');
?>
--FILE--
<?php
class SnapshotGarbage {
    public static int $calls = 0;
    public ?self $cycle = null;
    public function __destruct() {
        self::$calls++;
    }
}
class SnapshotHook {
    public int $value = 0;
    public static int $before;
    public function __serialize(): array {
        echo 'destructors before hook=', SnapshotGarbage::$calls - self::$before, "\n";
        return ['value' => 42];
    }
}

function makeInput(bool $packed, bool $shared): array {
    $array = [new stdClass()];
    $object = new stdClass();
    $input = $packed
        ? [&$array, &$object, new SnapshotHook()]
        : ['array' => &$array, 'object' => &$object, 'hook' => new SnapshotHook()];
    $owners = $shared ? [&$array, &$object] : null;
    return [$input, $owners];
}
foreach ([false, true] as $packed) {
    foreach ([false, true] as $shared) {
        echo $packed ? 'packed ' : 'assoc ', $shared ? "shared\n" : "sole\n";
        gc_disable();
        [$input, $owners] = makeInput($packed, $shared);
        gc_collect_cycles();
        $threshold = gc_status()['threshold'];
        while (gc_status()['roots'] < $threshold - 1) {
            $garbage = new SnapshotGarbage();
            $garbage->cycle = $garbage;
            unset($garbage);
        }
        gc_enable();
        SnapshotHook::$before = SnapshotGarbage::$calls;
        $decoded = array_values(phpser_unserialize(phpser_serialize($input)));
        var_dump($decoded[0][0] instanceof stdClass,
            $decoded[1] instanceof stdClass, $decoded[2]->value === 42);
        gc_collect_cycles();
        echo SnapshotGarbage::$calls > SnapshotHook::$before ? "GC control OK\n" : "GC control FAIL\n";
        unset($input, $owners, $decoded);
    }
}
?>
--EXPECT--
assoc sole
destructors before hook=0
bool(true)
bool(true)
bool(true)
GC control OK
assoc shared
destructors before hook=0
bool(true)
bool(true)
bool(true)
GC control OK
packed sole
destructors before hook=0
bool(true)
bool(true)
bool(true)
GC control OK
packed shared
destructors before hook=0
bool(true)
bool(true)
bool(true)
GC control OK
