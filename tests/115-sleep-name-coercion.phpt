--TEST--
phpser: integer __sleep member names warn and select the matching property
--EXTENSIONS--
phpser
--FILE--
<?php
#[AllowDynamicProperties]
class SleepIntegerName {
    public function __sleep(): array {
        return [123];
    }
}

$value = new SleepIntegerName();
$value->{'123'} = 'kept';
$warnings = [];
set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
    if ($severity === E_WARNING) {
        $warnings[] = $message;
        return true;
    }
    return false;
});
$roundtrip = phpser_unserialize(phpser_serialize($value));
restore_error_handler();

echo count($warnings) === 1 ? "warning OK\n" : "warning FAIL\n";
echo (((array) $roundtrip)[123] ?? null) === 'kept' ? "property OK\n" : "property FAIL\n";
?>
--EXPECT--
warning OK
property OK
