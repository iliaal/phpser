--TEST--
phpser: TAG_OBJECT_SLOTS snapshots later typed properties before magic callbacks mutate them
--EXTENSIONS--
phpser
--FILE--
<?php
class SlotMutator {
    public SlotOuter $outer;
    public function __serialize(): array {
        unset($this->outer->later);
        return [];
    }
    public function __unserialize(array $data): void {}
}

class SlotOuter {
    public SlotMutator $first;
    public string $later = 'original';
}

$value = new SlotOuter;
$value->first = new SlotMutator;
$value->first->outer = $value;

$payload = phpser_serialize($value);
$result = phpser_unserialize($payload);

echo ord($payload[0]), "\n";
echo $result->later, "\n";
?>
--EXPECT--
2
original
