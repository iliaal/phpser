--TEST--
phpser: non-serializable objects reject the entire encode through every userland wrapper
--EXTENSIONS--
phpser
--INI--
phpser.key=nonserializable-key-0123456789abcdef
--FILE--
<?php
class NonserializableBox260 {
    public function __construct(public mixed $value) {}
}
class NonserializableHook260 {
    public function __construct(private mixed $value) {}
    public function __serialize(): array { return ['value' => $this->value]; }
}
class AfterFailure260 {
    public static int $calls = 0;
    public function __serialize(): array { self::$calls++; return []; }
}
$target = new stdClass();
$values = [
    function () {},
    (function () { yield 1; })(),
    new Fiber(function () {}),
    new WeakMap(),
    WeakReference::create($target),
];
$key = 'nonserializable-key-0123456789abcdef';
$wrappers = [
    'plain' => [fn($v) => phpser_serialize($v), fn($s) => phpser_unserialize($s)],
    'signed' => [fn($v) => phpser_serialize_signed($v, $key), fn($s) => phpser_unserialize_signed($s, $key)],
    'keyed' => [fn($v) => phpser_serialize_keyed($v, 'test260'), fn($s) => phpser_unserialize_keyed($s, 'test260')],
];
foreach ($wrappers as $name => [$encode, $decode]) {
    $ok = true;
    foreach ($values as $value) {
        // Obtain native's exception class/message independently for each kind.
        try { serialize($value); $ok = false; }
        catch (Throwable $native) {}
        $cycle = new NonserializableBox260($value);
        $cycle->value = [$cycle, $value];
        $ref = $value;
        $shapes = [$value, ['before' => 42, 'bad' => $value, 'after' => new AfterFailure260()],
            [$value, $value], [['id' => 1, 'bad' => $value], ['id' => 2, 'bad' => $value]],
            new NonserializableBox260($value),
            new NonserializableHook260($value), $cycle, [&$ref, &$ref]];
        foreach ($shapes as $shape) {
            $result = 'unchanged';
            try { $result = $encode($shape); $ok = false; }
            catch (Throwable $e) {
                $ok = $ok && get_class($e) === get_class($native)
                    && $e->getMessage() === $native->getMessage();
            }
            $ok = $ok && $result === 'unchanged';
            // Failure must not poison the next encode, including genuine null.
            $ok = $ok && $decode($encode(['valid' => null])) === ['valid' => null];
        }
        unset($cycle, $shapes, $ref);
    }
    echo "$name: ", $ok ? "OK" : "FAIL", "\n";
}
echo 'later hooks: ', AfterFailure260::$calls === 0 ? 'OK' : 'FAIL', "\n";
$resource = fopen('php://memory', 'r+');
foreach ($wrappers as $name => [$encode, $decode]) {
    echo "$name resource: ", $decode($encode($resource)) === null ? 'OK' : 'FAIL', "\n";
}
fclose($resource);
?>
--EXPECT--
plain: OK
signed: OK
keyed: OK
later hooks: OK
plain resource: OK
signed resource: OK
keyed resource: OK
