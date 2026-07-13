--TEST--
phpser: temporary objects cannot alias later allocations in the encoder identity table
--EXTENSIONS--
phpser
--FILE--
<?php
class IdentityState { public static $next; }
class IdentityTemp {}
class IdentityVictim {}

class IdentityAllocator {
    public function __destruct() {
        IdentityState::$next = new IdentityVictim;
    }
}

class IdentityFirst {
    public array $data;
    public function __serialize(): array {
        return [new IdentityTemp, new IdentityAllocator];
    }
    public function __unserialize(array $data): void {
        $this->data = $data;
    }
}

class IdentitySecond {
    public array $data;
    public function __serialize(): array {
        return [IdentityState::$next];
    }
    public function __unserialize(array $data): void {
        $this->data = $data;
    }
}

IdentityState::$next = null;
$result = phpser_unserialize(phpser_serialize([
    new IdentityFirst,
    new IdentitySecond,
]));

echo get_class($result[1]->data[0]), "\n";
echo $result[0]->data[0] === $result[1]->data[0] ? "same\n" : "distinct\n";

class SharedArrayObject {}
$shared = [new SharedArrayObject];
$value = [$shared, $shared];
unset($shared);
$result = phpser_unserialize(phpser_serialize($value));
echo $result[0][0] === $result[1][0] ? "shared array identity\n" : "lost array identity\n";

// Transitive: the object sits in an RC1 array that is itself inside the
// shared array. Both walks of the shared ancestor re-walk the RC1 array,
// so the object must still be identity-tracked (native emits r:N here).
class NestedSharedObject {}
$mid = [new NestedSharedObject];
$shared = [$mid];
unset($mid);
$value = [$shared, $shared];
unset($shared);
$result = phpser_unserialize(phpser_serialize($value));
echo $result[0][0][0] === $result[1][0][0] ? "nested shared identity\n" : "lost nested identity\n";
?>
--EXPECT--
IdentityVictim
distinct
shared array identity
nested shared identity
