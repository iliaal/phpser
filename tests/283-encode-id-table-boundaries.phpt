--TEST--
phpser: identities survive repeats and new entries across encoder id-table boundaries
--EXTENSIONS--
phpser
--FILE--
<?php
foreach (['objects', 'references', 'arrays'] as $kind) {
    foreach ([7, 8, 9, 15, 16, 17, 31, 32, 33, 1024] as $count) {
        $owners = [];
        $input = [null];
        for ($i = 0; $i < $count; $i++) {
            if ($kind === 'objects') {
                $owners[] = (object)['id' => $i];
                $input[] = $owners[$i];
            } elseif ($kind === 'references') {
                $owners[] = $i;
                $input[] =& $owners[$i];
            } else {
                $owners[] = [$i];
                $input[] = $owners[$i];
            }
        }
        // Revisit every old slot, then insert one new identity and revisit it.
        // Reverse order exercises slots throughout the table after a rehash.
        for ($i = $count - 1; $i >= 0; $i--) {
            if ($kind === 'references') $input[] =& $owners[$i];
            else $input[] = $owners[$i];
        }
        if ($kind === 'objects') $extra = (object)['id' => $count];
        elseif ($kind === 'references') $extra = $count;
        else $extra = [$count];
        if ($kind === 'references') {
            $input[] =& $extra;
            $input[] =& $extra;
        } else {
            $input[] = $extra;
            $input[] = $extra;
        }
        $decoded = phpser_unserialize(phpser_serialize($input));
        if ($decoded != $input) die("$kind/$count values changed\n");
        for ($i = 0; $i <= $count; $i++) {
            $first = $i < $count ? $i + 1 : 2 * $count + 1;
            $second = $i < $count ? 2 * $count - $i : $first + 1;
            if ($kind === 'objects') {
                if ($decoded[$first] !== $decoded[$second]) die("object identity lost\n");
            } elseif ($kind === 'references') {
                $decoded[$first] = -1;
                if ($decoded[$second] !== -1) die("reference alias lost\n");
            } else {
                $decoded[$first][0] = -1;
                if ($decoded[$second][0] !== $i) die("array COW lost\n");
            }
        }
        unset($extra);
    }
    echo "$kind: boundaries OK\n";
}
?>
--EXPECT--
objects: boundaries OK
references: boundaries OK
arrays: boundaries OK
