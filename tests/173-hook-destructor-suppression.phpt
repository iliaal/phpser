--TEST--
phpser: destructors wait for successful wakeup or unserialize initialization
--EXTENSIONS--
phpser
--FILE--
<?php
class HookGuard {
    public $value;
    public function __destruct() { $GLOBALS['events'][] = 'destruct'; }
}
class WakeGuard extends HookGuard {
    public function __wakeup() {
        $GLOBALS['events'][] = 'wakeup';
        if ($GLOBALS['throw'] === true || $GLOBALS['throw'] === count($GLOBALS['events'])) {
            throw new RuntimeException('guard');
        }
    }
}
class MagicGuard extends HookGuard {
    public function __unserialize(array $data): void {
        $GLOBALS['events'][] = 'unserialize';
        if ($GLOBALS['throw'] === true || $GLOBALS['throw'] === count($GLOBALS['events'])) {
            throw new RuntimeException('guard');
        }
        $this->value = $data['value'];
    }
}
function frame(string $class, string $body): string {
    return "\x02\x02" . chr(strlen($class)) . $class . "\x05value" . $body;
}
function check(string $label, string $payload, array $expected, bool $fails): void {
    $GLOBALS['events'] = [];
    try {
        $result = phpser_unserialize($payload);
        $failed = $result === null;
        if (!$failed && $result->value !== 7) echo "wrong property\n";
        unset($result);
    } catch (RuntimeException $e) {
        $failed = true;
        if ($e->getMessage() !== 'guard') echo "wrong exception\n";
    }
    gc_collect_cycles();
    echo $label, ': ', $failed === $fails && $GLOBALS['events'] === $expected
        ? 'OK' : json_encode([$failed, $GLOBALS['events']]), "\n";
}
$forms = [
    'object' => ["\x0a\x00\x01\x01\x03\x0e", "\x0a\x00\x01\x01\xff"],
    'magic' => ["\x0e\x00\x06\x01\x01\x01\x03\x0e", "\x0e\x00\x06\x01\x01\x01\xff"],
    'slots' => ["\x12\x00\x01\x03\x0e", "\x12\x00\x01\xff"],
];
foreach (['WakeGuard' => 'wakeup', 'MagicGuard' => 'unserialize'] as $class => $hook) {
    foreach ($forms as $form => [$complete, $partial]) {
        $label = "$class/$form";
        $throw = true;
        check("$label/throw", frame($class, $complete), [$hook], true);
        $throw = false;
        check("$label/partial", frame($class, $partial), [], true);
        check("$label/pending", frame($class, "\x07\x02" . $complete . "\xff"), [], true);
        check("$label/success", frame($class, $complete), [$hook, 'destruct'], false);
    }
}
$throw = true;
check('later hook remains pending', frame('WakeGuard', "\x07\x02\x0a\x00\x00\x0a\x00\x00"), ['wakeup'], true);
$throw = 2;
check('earlier hook succeeded', frame('WakeGuard', "\x07\x02\x0a\x00\x00\x0a\x00\x00"), ['wakeup', 'wakeup', 'destruct'], true);

class HookReturnValue {
    public function __destruct() {
        $GLOBALS['events'][] = 'return destructor';
        throw new RuntimeException('return cleanup');
    }
}
class SuccessfulWakeup {
    public function __wakeup() {
        $GLOBALS['events'][] = 'wakeup complete';
        return new HookReturnValue();
    }
    public function __destruct() { $GLOBALS['events'][] = 'object destructor'; }
}
foreach (['native', 'phpser'] as $decoder) {
    $events = [];
    try {
        if ($decoder === 'native') {
            unserialize('O:16:"SuccessfulWakeup":0:{}');
        } else {
            phpser_unserialize(frame(SuccessfulWakeup::class, "\x0a\x00\x00"));
        }
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'return cleanup') echo "wrong return exception\n";
    }
    gc_collect_cycles();
    echo $decoder, ' return cleanup: ', $events === [
        'wakeup complete', 'return destructor', 'object destructor'
    ] ? 'OK' : json_encode($events), "\n";
}
?>
--EXPECT--
WakeGuard/object/throw: OK
WakeGuard/object/partial: OK
WakeGuard/object/pending: OK
WakeGuard/object/success: OK
WakeGuard/magic/throw: OK
WakeGuard/magic/partial: OK
WakeGuard/magic/pending: OK
WakeGuard/magic/success: OK
WakeGuard/slots/throw: OK
WakeGuard/slots/partial: OK
WakeGuard/slots/pending: OK
WakeGuard/slots/success: OK
MagicGuard/object/throw: OK
MagicGuard/object/partial: OK
MagicGuard/object/pending: OK
MagicGuard/object/success: OK
MagicGuard/magic/throw: OK
MagicGuard/magic/partial: OK
MagicGuard/magic/pending: OK
MagicGuard/magic/success: OK
MagicGuard/slots/throw: OK
MagicGuard/slots/partial: OK
MagicGuard/slots/pending: OK
MagicGuard/slots/success: OK
later hook remains pending: OK
earlier hook succeeded: OK
native return cleanup: OK
phpser return cleanup: OK
