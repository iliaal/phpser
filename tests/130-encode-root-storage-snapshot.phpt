--TEST--
phpser: root storage shared with ArrayObject/ArrayIterator is snapshotted before hook mutation
--DESCRIPTION--
A value extracted from ArrayObject/ArrayIterator::__serialize() aliases the
container's live internal storage. At the encoder root this table is shared,
but the prior root fast path walked it directly. A nested __serialize hook
could append or unset the same table through the SPL owner, reallocating or
freeing the bucket storage under the raw walk pointers. The root must use a
private pre-mutation snapshot for both generic and columnar walks.
--SKIPIF--
<?php
if (!extension_loaded("phpser")) die("skip phpser not loaded");
if (PHP_DEBUG) die("skip debug build aborts on SPL HT_ASSERT_RC1 before the walk");
?>
--FILE--
<?php

class RootAppend {
    public static ?ArrayObject $owner = null;
    public int $value = 0;
    public function __serialize(): array {
        for ($i = 0; $i < 64; $i++) {
            self::$owner["grown$i"] = str_repeat("x", 32);
        }
        return ['value' => 1];
    }
    public function __unserialize(array $data): void {
        $this->value = $data['value'];
    }
}
$owner = new ArrayObject(['obj' => new RootAppend(), 'a' => 1, 'b' => 2]);
RootAppend::$owner = $owner;
$storage = $owner->__serialize()[1];
$payload = phpser_serialize($storage);
RootAppend::$owner = null;
$decoded = phpser_unserialize($payload);
$ok = is_string($payload)
    && is_array($decoded)
    && ($decoded['a'] ?? null) === 1
    && ($decoded['b'] ?? null) === 2
    && $decoded['obj'] instanceof RootAppend
    && $decoded['obj']->value === 1
    && !isset($decoded['grown0']);
echo $ok ? "root_generic_snapshot OK\n" : "root_generic_snapshot FAIL\n";

class RootAlias {
    public static ?ArrayObject $owner = null;
    public $alias = null;
    public function __serialize(): array {
        return ['alias' => &self::$owner['x']];
    }
    public function __unserialize(array $data): void {
        $this->alias =& $data['alias'];
    }
}
$referenceValue = 7;
$referenceSource = ['x' => &$referenceValue, 'hook' => new RootAlias()];
$aliasOwner = new ArrayObject($referenceSource);
unset($referenceSource, $referenceValue);
RootAlias::$owner = $aliasOwner;
$aliasRoot = $aliasOwner->__serialize()[1];
$aliasPayload = phpser_serialize($aliasRoot);
RootAlias::$owner = null;
$aliasDecoded = phpser_unserialize($aliasPayload);
$aliasDecoded['hook']->alias = 99;
$aliasOk = is_string($aliasPayload)
    && is_array($aliasDecoded)
    && ($aliasDecoded['x'] ?? null) === 99
    && $aliasDecoded['hook'] instanceof RootAlias
    && $aliasDecoded['hook']->alias === 99;
echo $aliasOk ? "root_reference_alias OK\n" : "root_reference_alias FAIL\n";

class GenericDescendantAlias {
    public static ?ArrayObject $owner = null;
    public $alias = null;
    public function __serialize(): array {
        return ['alias' => &self::$owner['nested']['r']];
    }
    public function __unserialize(array $data): void {
        $this->alias =& $data['alias'];
    }
}
$genericReference = 7;
$genericNested = ['r' => &$genericReference, 'hook' => new GenericDescendantAlias()];
$genericSource = ['nested' => $genericNested];
$genericOwner = new ArrayObject($genericSource);
unset($genericSource, $genericNested, $genericReference);
GenericDescendantAlias::$owner = $genericOwner;
$genericRoot = $genericOwner->__serialize()[1];
$genericPayload = phpser_serialize($genericRoot);
GenericDescendantAlias::$owner = null;
$genericDecoded = phpser_unserialize($genericPayload);
$genericDecoded['nested']['hook']->alias = 99;
$genericDescendantOk = is_string($genericPayload)
    && is_array($genericDecoded)
    && ($genericDecoded['nested']['r'] ?? null) === 99
    && $genericDecoded['nested']['hook'] instanceof GenericDescendantAlias
    && $genericDecoded['nested']['hook']->alias === 99;
echo $genericDescendantOk ? "generic_descendant_alias OK\n" : "generic_descendant_alias FAIL\n";

class ColumnDescendantAlias {
    public static ?ArrayIterator $owner = null;
    public $alias = null;
    public $value = 0;
    public function __serialize(): array {
        if (self::$owner[0]['a'] === $this) {
            return ['alias' => &self::$owner[0]['r']];
        }
        return ['value' => 1];
    }
    public function __unserialize(array $data): void {
        if (array_key_exists('alias', $data)) {
            $this->alias =& $data['alias'];
        } else {
            $this->value = $data['value'];
        }
    }
}
$columnReference = 7;
$columnRow0 = ['a' => new ColumnDescendantAlias(), 'r' => &$columnReference];
$columnRow1 = ['a' => new ColumnDescendantAlias(), 'r' => 8];
$columnSource = [$columnRow0, $columnRow1];
$columnOwner = new ArrayIterator($columnSource);
unset($columnSource, $columnRow0, $columnRow1, $columnReference);
ColumnDescendantAlias::$owner = $columnOwner;
$columnRoot = $columnOwner->__serialize()[1];
$columnAliasPayload = phpser_serialize($columnRoot);
ColumnDescendantAlias::$owner = null;
$columnAliasDecoded = phpser_unserialize($columnAliasPayload);
$columnAliasDecoded[0]['a']->alias = 99;
$columnDescendantOk = is_string($columnAliasPayload)
    && is_array($columnAliasDecoded)
    && ($columnAliasDecoded[0]['r'] ?? null) === 99
    && $columnAliasDecoded[0]['a'] instanceof ColumnDescendantAlias
    && $columnAliasDecoded[0]['a']->alias === 99;
echo $columnDescendantOk ? "column_descendant_alias OK\n" : "column_descendant_alias FAIL\n";

class RootColumnCell {
    public static ?ArrayIterator $owner = null;
    public int $value = 0;
    public function __serialize(): array {
        self::$owner->offsetUnset(0);
        return ['value' => 1];
    }
    public function __unserialize(array $data): void {
        $this->value = $data['value'];
    }
}
$rows = [
    ['a' => new RootColumnCell(), 'b' => str_repeat('y', 64)],
    ['a' => new RootColumnCell(), 'b' => str_repeat('z', 64)],
];
$rowOwner = new ArrayIterator($rows);
unset($rows);
$rootRows = $rowOwner->__serialize()[1];
RootColumnCell::$owner = $rowOwner;
$columnPayload = phpser_serialize($rootRows);
RootColumnCell::$owner = null;
$columnDecoded = phpser_unserialize($columnPayload);
$columnOk = is_string($columnPayload)
    && is_array($columnDecoded)
    && count($columnDecoded) === 2
    && ($columnDecoded[0]['b'] ?? null) === str_repeat('y', 64)
    && ($columnDecoded[1]['b'] ?? null) === str_repeat('z', 64)
    && $columnDecoded[0]['a'] instanceof RootColumnCell
    && $columnDecoded[1]['a'] instanceof RootColumnCell
    && $columnDecoded[0]['a']->value === 1
    && $columnDecoded[1]['a']->value === 1;
echo $columnOk ? "root_column_snapshot OK\n" : "root_column_snapshot FAIL\n";
?>
--EXPECT--
root_generic_snapshot OK
root_reference_alias OK
generic_descendant_alias OK
column_descendant_alias OK
root_column_snapshot OK
