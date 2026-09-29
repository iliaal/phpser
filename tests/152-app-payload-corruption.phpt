--TEST--
phpser: truncated or corrupted real-app payloads decode to null (unsigned) / throw (signed)
--EXTENSIONS--
phpser
--FILE--
<?php
require __DIR__ . '/145-app-fixtures.inc';

$key = 'app-cache-key';
$values = [
    'queue_job' => mk_queue_job(),
    'eloquent_model' => mk_eloquent_model(5),
    'eloquent_coll_30' => mk_eloquent_collection(30),
    'wallet' => mk_wallet(),
    'catalog' => mk_catalog(),
];

// Every prefix for small payloads; an even sample plus the tail for big ones.
function cut_points(int $len): array {
    if ($len <= 4096) return range(0, $len - 1);
    $points = [];
    for ($i = 0; $i < 400; $i++) $points[] = intdiv($len * $i, 400);
    for ($i = 1; $i <= 64; $i++) $points[] = $len - $i;
    return array_values(array_unique($points));
}

foreach ($values as $label => $value) {
    $payload = phpser_serialize($value);
    $signed = phpser_serialize_signed($value, $key);
    $len = strlen($payload);

    $bad_unsigned = 0;
    $bad_signed = 0;
    $points = cut_points($len);
    foreach ($points as $cut) {
        try {
            if (phpser_unserialize(substr($payload, 0, $cut)) !== null) $bad_unsigned++;
        } catch (Throwable $e) {
            $bad_unsigned++;
        }
    }
    foreach (cut_points(strlen($signed)) as $cut) {
        try {
            phpser_unserialize_signed(substr($signed, 0, $cut), $key);
            $bad_signed++;
        } catch (Exception $e) {
            if (!str_starts_with($e->getMessage(), 'phpser: ')) $bad_signed++;
        }
    }

    // Single-bit flips across the frame and the MAC: signed must reject all of
    // them before decoding. Unsigned may legitimately decode a flipped byte
    // (a changed string character is still a valid frame); it only must not
    // crash, and hook exceptions from mangled __unserialize data are allowed.
    $flip_positions = $len <= 4096 ? range(0, strlen($signed) - 1) : cut_points(strlen($signed));
    $signed_flips_accepted = 0;
    foreach ($flip_positions as $pos) {
        $flipped = $signed;
        $flipped[$pos] = chr(ord($flipped[$pos]) ^ (1 << ($pos % 8)));
        try {
            phpser_unserialize_signed($flipped, $key);
            $signed_flips_accepted++;
        } catch (Exception $e) {
        }
        if ($pos < $len) {
            try {
                @phpser_unserialize(substr($flipped, 0, $len));
            } catch (Throwable $e) {
            }
        }
    }

    // Trailing garbage after a complete signed frame fails the MAC as well.
    try {
        phpser_unserialize_signed($signed . "\x00", $key);
        $bad_signed++;
    } catch (Exception $e) {
    }

    printf("%s: truncations_not_null=%d signed_accepted=%d signed_flips_accepted=%d (%s)\n",
        $label, $bad_unsigned, $bad_signed, $signed_flips_accepted,
        count($points) === $len ? 'every prefix' : 'sampled');

    // The intact payload still decodes after all that.
    if (serialize(phpser_unserialize($payload)) !== serialize($value)) echo "$label intact FAIL\n";
}

// Wrong key and empty input.
try {
    phpser_unserialize_signed(phpser_serialize_signed($values['queue_job'], $key), 'other-key');
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
var_dump(phpser_unserialize(''));
?>
--EXPECT--
queue_job: truncations_not_null=0 signed_accepted=0 signed_flips_accepted=0 (every prefix)
eloquent_model: truncations_not_null=0 signed_accepted=0 signed_flips_accepted=0 (every prefix)
eloquent_coll_30: truncations_not_null=0 signed_accepted=0 signed_flips_accepted=0 (sampled)
wallet: truncations_not_null=0 signed_accepted=0 signed_flips_accepted=0 (sampled)
catalog: truncations_not_null=0 signed_accepted=0 signed_flips_accepted=0 (sampled)
phpser: signature verification failed
NULL
