<?php
// Top-level scalar corpus: null, bools, integers at every zigzag varint width
// boundary, float edge values, and strings at every varint length boundary up
// to 2 MiB, including runtime-built, interned and binary strings.
// scalar_frame_corpus_digest() hashes the plain, signed and keyed frame of
// each value and checks every round trip; run it under two builds to compare
// their wire bytes. Integers beyond 32 bits are a separate section because
// they do not exist on 32-bit builds.
function scalar_frame_corpus(bool $wide_ints): Generator {
    yield 'null' => null;
    yield 'true' => true;
    yield 'false' => false;
    if (!$wide_ints) {
        foreach ([0, 1, -1, 2, -2, 63, -64, 64, -65, 127, 128, 255, 256, 4217, -4217] as $i) {
            yield "int/$i" => $i;
        }
        // zigzag(v) needs k+1 varint bytes once |v| reaches 2^(7k-1).
        for ($k = 1; $k <= 4; $k++) {
            $b = 1 << (7 * $k - 1);
            foreach ([$b - 1, $b, -$b, -$b - 1] as $i) yield "int/$i" => $i;
        }
        yield 'int/2147483647' => 2147483647;
        yield 'int/-2147483648' => -2147483647 - 1;
        mt_srand(289);
        for ($n = 0; $n < 200; $n++) {
            $i = mt_rand(-2147483647, 2147483647);
            yield "int/rand$n" => $i;
        }
        // NaNs and random values come from fixed bit patterns, so the digest
        // does not depend on the platform's NAN constant or libm.
        $floats = [0.0, -0.0, 1.5, -3.14159, 19.99, 1e-300, 1e300, 5e-324,
                   PHP_FLOAT_EPSILON, PHP_FLOAT_MIN, PHP_FLOAT_MAX, INF, -INF,
                   unpack('E', "\x7f\xf8\0\0\0\0\0\0")[1],
                   unpack('E', "\xff\xf8\0\0\0\0\0\0")[1],
                   unpack('E', "\x7f\xf8\0\0\0\0\0\x01")[1]];
        foreach ($floats as $n => $f) yield "float/$n" => $f;
        for ($n = 0; $n < 200; $n++) yield "float/rand$n" => unpack('E', random_bytes_seeded($n, 8))[1];
        foreach (['', 'a', 'hello', '123', '1.5', "\0", "\xff\xfe", 'cache:user:4217'] as $n => $s) {
            yield "str/lit$n" => $s;
        }
        for ($len = 0; $len <= 300; $len++) yield "str/len$len" => str_repeat(chr(65 + $len % 26), $len);
        foreach ([16383, 16384, 16385, 2097151, 2097152] as $len) {
            yield "str/len$len" => substr(str_repeat("\x00\x01binary\xff", intdiv($len, 9) + 1), 0, $len);
        }
        for ($n = 0; $n < 100; $n++) yield "str/rand$n" => random_bytes_seeded($n, mt_rand(0, 600));
        return;
    }
    if (PHP_INT_SIZE < 8) return;
    for ($k = 5; $k <= 9; $k++) {
        $b = 1 << (7 * $k - 1);
        foreach ([$b - 1, $b, -$b, -$b - 1] as $i) yield "int/$i" => $i;
    }
    yield 'int/2147483648' => 2147483648;
    yield 'int/-2147483649' => -2147483649;
    yield 'int/max' => PHP_INT_MAX;
    yield 'int/min' => PHP_INT_MIN;
    mt_srand(2890);
    for ($n = 0; $n < 200; $n++) yield "int/rand$n" => (mt_rand() << 32) ^ mt_rand() ^ (mt_rand(0, 1) ? PHP_INT_MIN : 0);
}

// Deterministic bytes without depending on random_bytes().
function random_bytes_seeded(int $seed, int $len): string {
    $out = '';
    for ($i = 0; strlen($out) < $len; $i++) $out .= hash('sha256', "$seed/$i", true);
    return substr($out, 0, $len);
}

// Floats compare by bit pattern, so NAN and -0.0 round trips are checked too.
function scalar_frame_same(mixed $a, mixed $b): bool {
    if (is_float($a)) return is_float($b) && pack('E', $a) === pack('E', $b);
    return $a === $b;
}

function scalar_frame_corpus_digest(bool $wide_ints): string {
    $h = hash_init('sha256');
    foreach (scalar_frame_corpus($wide_ints) as $name => $v) {
        $plain = phpser_serialize($v);
        $signed = phpser_serialize_signed($v, 'scalar-frame-signing-key');
        $keyed = phpser_serialize_keyed($v, 'scalar-frame');
        foreach ([$plain, $signed, $keyed] as $frame) {
            hash_update($h, pack('N', strlen($frame)) . $frame);
        }
        $back = [
            phpser_unserialize($plain),
            phpser_unserialize_signed($signed, 'scalar-frame-signing-key'),
            phpser_unserialize_keyed($keyed, 'scalar-frame'),
        ];
        foreach ($back as $b) {
            if (!scalar_frame_same($v, $b)) throw new Exception("round trip failed for $name");
        }
    }
    return hash_final($h);
}
