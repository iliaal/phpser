--TEST--
phpser: enum round-trip (PHP 8.1+, pure + backed)
--EXTENSIONS--
phpser
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80100) print "skip PHP 8.1+ required for enums";
?>
--FILE--
<?php

enum Status {
    case Draft;
    case Published;
    case Archived;
}

enum Color: string {
    case Red = 'r';
    case Green = 'g';
    case Blue = 'b';
}

enum Priority: int {
    case Low = 0;
    case Medium = 5;
    case High = 10;
}

$cases = [
    'pure_enum'     => Status::Published,
    'string_enum'   => Color::Green,
    'int_enum'      => Priority::High,
    'enum_in_array' => [Status::Draft, Status::Published, Status::Archived],
    'enum_assoc'    => ['status' => Status::Draft, 'priority' => Priority::High],
];

$fail = 0;
foreach ($cases as $name => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (serialize($rt) !== serialize($v)) {
        echo "MISMATCH $name\n";
        echo "  exp: " . serialize($v) . "\n";
        echo "  got: " . serialize($rt) . "\n";
        $fail++;
    }
}
echo $fail ? "FAILED $fail\n" : "OK\n";
?>
--EXPECT--
OK
