--TEST--
phpser: TAG_OBJECT_SLOTS wire v2 — declaration-order DTO encode/decode
--EXTENSIONS--
phpser
--FILE--
<?php

final class UserDto {
    public function __construct(
        public int $id,
        public int $tenant_id,
        public string $name,
        public string $email,
        public ?string $phone,
        public string $created_at,
        public bool $is_active,
        public array $tags,
    ) {}
}

$u = new UserDto(
    42, 1000, 'user_42', 'user42@example.com', null,
    '2026-05-19T12:00:00Z', true, ['a', 'b']
);
$blob = phpser_serialize($u);

// Wire v2: version 0x02 and TAG_OBJECT_SLOTS (0x12), not TAG_OBJECT (0x0a).
echo ($blob[0] === "\x02") ? "version_v2 OK\n" : "version_v2 FAIL\n";
echo (strpos($blob, "\x12") !== false) ? "tag_slots OK\n" : "tag_slots FAIL\n";
echo (strpos($blob, "\x0a") === false) ? "no_tag_object OK\n" : "no_tag_object FAIL\n";

$rt = phpser_unserialize($blob);
echo ($rt instanceof UserDto && $rt->id === 42 && $rt->phone === null && $rt->tags === ['a', 'b'])
    ? "roundtrip OK\n" : "roundtrip FAIL\n";

// Same-class batch (bench dto_* shape).
$batch = [];
for ($i = 0; $i < 20; $i++) {
    $batch[] = new UserDto(
        $i, 1000 + ($i % 5), "user_$i", "u$i@example.com",
        ($i % 2) ? null : '555', '2026-05-19T12:00:00Z', true, ['x']
    );
}
$bb = phpser_serialize($batch);
$rb = phpser_unserialize($bb);
echo (serialize($rb) === serialize($batch)) ? "batch_roundtrip OK\n" : "batch_roundtrip FAIL\n";

// Classes with __unserialize stay on TAG_OBJECT (0x0a).
class HasUnser {
    public int $n = 1;
    public function __unserialize(array $data): void { $this->n = (int)$data['n']; }
}
$legacy = phpser_serialize(new HasUnser());
echo (strpos($legacy, "\x0a") !== false) ? "unserialize_class_uses_object OK\n"
    : "unserialize_class_uses_object FAIL\n";
echo (phpser_unserialize($legacy)->n === 1) ? "unserialize_class_rt OK\n"
    : "unserialize_class_rt FAIL\n";

// v1 payloads still decode (version 0x01, empty dict, TAG_LONG zigzag(42)=84).
$v1 = "\x01\x00\x03\x54";
echo (phpser_unserialize($v1) === 42) ? "v1_compat OK\n" : "v1_compat FAIL\n";

// Crafted TAG_OBJECT_SLOTS with wrong nprops rejects cleanly.
$crafted = $blob;
$crafted[9] = "\x09"; // bump nprops byte if single-byte varint — fragile; build minimal:
$bad = "\x02\x01UserDto\x02\x01\x03\xff"; // v2, dict UserDto, slots nprops=1, truncated
echo (phpser_unserialize($bad) === null) ? "bad_slots_reject OK\n" : "bad_slots_reject FAIL\n";
?>
--EXPECT--
version_v2 OK
tag_slots OK
no_tag_object OK
roundtrip OK
batch_roundtrip OK
unserialize_class_uses_object OK
unserialize_class_rt OK
v1_compat OK
bad_slots_reject OK