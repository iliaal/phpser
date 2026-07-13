--TEST--
phpser: deferred __wakeup and __unserialize hooks preserve encounter order
--EXTENSIONS--
phpser
--FILE--
<?php
class HookOrderLog { public static array $events = []; }

class HookOrderWakeup {
    public function __wakeup(): void {
        HookOrderLog::$events[] = 'W';
    }
}

class HookOrderUnserialize {
    public function __serialize(): array {
        return [];
    }
    public function __unserialize(array $data): void {
        HookOrderLog::$events[] = 'U';
    }
}

foreach ([
    [new HookOrderWakeup, new HookOrderUnserialize],
    [new HookOrderUnserialize, new HookOrderWakeup],
] as $value) {
    HookOrderLog::$events = [];
    phpser_unserialize(phpser_serialize($value));
    echo implode('', HookOrderLog::$events), "\n";
}
?>
--EXPECT--
WU
UW
