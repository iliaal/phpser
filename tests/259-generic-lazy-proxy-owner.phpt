--TEST--
phpser: generic lazy proxy serialization retains its backing instance through nested callbacks
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) die('skip requires PHP 8.4+ lazy objects');
?>
--FILE--
<?php
class GenericProxyOwner {
    public $hook;

    public function __construct() {
        $this->hook = new GenericProxyHook;
    }
}

class GenericProxyHook {
    public function __serialize(): array {
        global $proxy, $reflection, $ownerRef, $throw;
        $reflection->resetAsLazyProxy($proxy, fn() => new GenericProxyOwner);
        echo 'during callback: ', $ownerRef->get() !== null ? 'alive' : 'released', "\n";
        if ($throw) {
            throw new RuntimeException('hook failed');
        }
        return [];
    }
}

$reflection = new ReflectionClass(GenericProxyOwner::class);
foreach ([false, true] as $throw) {
    echo $throw ? "exception\n" : "success\n";
    $ownerRef = null;
    $proxy = $reflection->newLazyProxy(function () use (&$ownerRef) {
        $owner = new GenericProxyOwner;
        $ownerRef = WeakReference::create($owner);
        return $owner;
    });
    try {
        $payload = phpser_serialize($proxy);
        $decoded = phpser_unserialize($payload);
        var_dump($decoded instanceof GenericProxyOwner);
        var_dump($decoded->hook instanceof GenericProxyHook);
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";
    }
    echo 'after serialization: ', $ownerRef->get() === null ? 'released' : 'retained', "\n";
    var_dump($reflection->isUninitializedLazyObject($proxy));
}
?>
--EXPECT--
success
during callback: alive
bool(true)
bool(true)
after serialization: released
bool(true)
exception
during callback: alive
hook failed
after serialization: released
bool(true)
