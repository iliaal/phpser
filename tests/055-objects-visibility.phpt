--TEST--
phpser: protected/private property handling
--EXTENSIONS--
phpser
--FILE--
<?php

// PHP's serialize() mangles protected ("\0*\0name") and private ("\0Class\0name")
// property keys when emitting them through get_properties(). Our encoder reads
// the same HashTable, so the same mangled keys flow into the dict. The decoder
// installs them back via write_property which honors the mangling.

class WithVisibility
{
    public int $pub = 1;
    protected int $prot = 2;
    private int $priv = 3;
}

class WithVisibilitySub extends WithVisibility
{
    public int $sub_pub = 4;
}

$cases = [
    'visibility_basic' => new WithVisibility(),
    'visibility_sub'   => new WithVisibilitySub(),
    'in_array'         => [new WithVisibility(), new WithVisibility()],
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
