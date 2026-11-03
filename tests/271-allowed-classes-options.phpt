--TEST--
phpser: allowed_classes types, string conversion, and exact native diagnostics
--EXTENSIONS--
phpser
--INI--
phpser.key=0123456789abcdef0123456789abcdef
--FILE--
<?php
class OptionProbe {
    public static int $hooks = 0;
    public function __unserialize(array $data): void { self::$hooks++; }
}
class OptionName {
    public function __construct(public string $name) {}
    public function __toString(): string { return $this->name; }
}
class OptionThrow {
    public function __toString(): string { throw new LogicException('conversion stopped'); }
}
$value = new OptionProbe;
$native = serialize($value);
$plain = phpser_serialize($value);
$signed = phpser_serialize_signed($value, 'key');
$keyed = phpser_serialize_keyed($value, 'test');
$decoders = [
    'phpser_unserialize' => fn($o) => phpser_unserialize($plain, $o),
    'phpser_unserialize_signed' => fn($o) => phpser_unserialize_signed($signed, 'key', $o),
    'phpser_unserialize_keyed' => fn($o) => phpser_unserialize_keyed($keyed, 'test', $o),
];
function outcome(callable $decode, array $options): array {
    OptionProbe::$hooks = 0;
    try { $v = $decode($options); return ['ok', get_class($v), OptionProbe::$hooks]; }
    catch (Throwable $e) { return [get_class($e), $e->getMessage(), OptionProbe::$hooks]; }
}
function check(bool $ok, string $label): void {
    if (!$ok) echo "FAIL $label\n";
}
$resource = fopen('php://memory', 'r+');
foreach ($decoders as $function => $decode) {
    $cases = [[], ['allowed_classes' => true], ['allowed_classes' => false],
        ['allowed_classes' => []], ['allowed_classes' => ['OPTIONPROBE', 'optionprobe']]];
    foreach ([null, 0, 1.5, '', new stdClass, $resource] as $bad) {
        $cases[] = ['allowed_classes' => $bad];
    }
    if (PHP_VERSION_ID >= 80400) {
        foreach ([null, false, true, 0, 1.5, [], $resource, new stdClass,
            new OptionThrow, new OptionName('OPTIONPROBE'), new OptionName('bad name'),
            'bad name', "bad\0name", 'bad-name', 'bad/name'] as $entry) {
            $cases[] = ['allowed_classes' => [OptionProbe::class, $entry]];
        }
        $name = new OptionName('OPTIONPROBE');
        $classes = [&$name, '', '123', '\\Unknown\\Name', 'Éclair'];
        $cases[] = ['allowed_classes' => &$classes];
    }
    foreach ($cases as $i => $options) {
        $expected = outcome(fn($o) => unserialize($native, $o), $options);
        $actual = outcome($decode, $options);
        if ($expected[0] !== 'ok') {
            $expected[1] = str_replace('unserialize():', "$function():", $expected[1]);
            check($actual[2] === 0, "$function case $i rejected before hooks");
        }
        // Compare the complete exception class and byte-exact message, not a substring.
        check($actual === $expected, "$function case $i " . json_encode([$expected, $actual]));
        check(outcome($decode, [])[0] === 'ok', "$function usable after case $i");
    }
    if (PHP_VERSION_ID >= 80400) {
        check($name instanceof OptionName && $name->name === 'OPTIONPROBE', 'input object unchanged');
        check($classes[0] === $name, 'referenced input unchanged');
    } else {
        // Deliberate compatibility divergence: older native PHP coerces entries;
        // phpser has always required strings before PHP 8.4.
        foreach ([123, new OptionName('OptionProbe')] as $entry) {
            $actual = outcome($decode, ['allowed_classes' => [$entry]]);
            $type = is_int($entry) ? 'int' : 'OptionName';
            check($actual === ['TypeError', "$function(): Option \"allowed_classes\" must be an array of class names, $type given", 0], 'legacy strictness');
        }
    }
    echo "$function checked\n";
}
fclose($resource);
?>
--EXPECT--
phpser_unserialize checked
phpser_unserialize_signed checked
phpser_unserialize_keyed checked
