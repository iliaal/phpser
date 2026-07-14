<?php
// bench.php: phpser vs igbinary, native serialize(), and msgpack on the
// shapes that actually show up in cache. igbinary is the reference column;
// deltas are reported against it.
//
// Text (default):
//   php -d extension=./modules/phpser.so -d extension=igbinary.so bench.php
//
// HTML page (writes a self-contained doc to stdout):
//   php ... bench.php --html > docs/index.html
//
// Knobs (env): BENCH_ITERS (inner loop, default 1000),
//              BENCH_REPS  (timed repetitions, median reported, default 7).

declare(strict_types=1);

function mk_rowset(int $rows): array {
    $out = [];
    for ($i = 0; $i < $rows; $i++) {
        $out[] = [
            'id' => $i,
            'user_id' => 1000 + ($i % 50),
            'name' => 'row_' . $i,
            'created_at' => '2026-05-19T12:00:00Z',
            'amount' => $i * 1.07,
            'active' => ($i % 3) === 0,
            'tags' => ['a', 'b', 'c'],
        ];
    }
    return $out;
}

function distinct_string(string $value): string {
    return substr($value . "\0", 0, strlen($value));
}

function mk_rowset_distinct(int $rows): array {
    $out = mk_rowset($rows);
    foreach ($out as &$row) {
        $row['created_at'] = distinct_string($row['created_at']);
        foreach ($row['tags'] as &$tag) {
            $tag = distinct_string($tag);
        }
        unset($tag);
    }
    unset($row);
    return $out;
}

function mk_numeric_packed(int $n): array {
    return range(0, $n - 1);
}

function mk_deep_nested(int $depth): array {
    $cur = ['leaf' => 42];
    for ($i = 0; $i < $depth; $i++) {
        $cur = ['next' => $cur, 'i' => $i];
    }
    return $cur;
}

// DTO shapes modeled after Laravel queue payloads
// (Illuminate\Queue\SerializableClosure, Eloquent ModelIdentifier).
// Real apps serialize batches of small typed objects through queue/cache:
// the per-object cost (class entry lookup, property iteration, dict ref
// per prop name) is the workload the rowset bench doesn't cover.

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

final class OrderDto {
    public function __construct(
        public int $id,
        public int $user_id,
        public string $sku,
        public float $amount,
        public string $currency,
        public string $status,
        public ?string $shipped_at,
    ) {}
}

function mk_dto_users(int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = new UserDto(
            id: $i,
            tenant_id: 1000 + ($i % 50),
            name: 'user_' . $i,
            email: "user{$i}@example.com",
            phone: ($i % 3 === 0) ? null : '+1-555-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
            created_at: '2026-05-19T12:00:00Z',
            is_active: ($i % 7) !== 0,
            tags: ['active', 'verified', 'beta'],
        );
    }
    return $out;
}

// Mixed object payload: a typical "job with relations" shape with a UserDto
// containing references to OrderDto array. Exercises class-entry lookup
// for two distinct classes interleaved.
function mk_dto_mixed(int $users): array {
    $out = [];
    for ($i = 0; $i < $users; $i++) {
        $orders = [];
        for ($j = 0; $j < 3; $j++) {
            $orders[] = new OrderDto(
                id: $i * 10 + $j,
                user_id: $i,
                sku: 'SKU-' . ($i * 10 + $j),
                amount: ($i + $j) * 12.99,
                currency: 'USD',
                status: ['pending', 'shipped', 'delivered'][$j % 3],
                shipped_at: $j === 0 ? null : '2026-05-19T12:00:00Z',
            );
        }
        $out[] = [
            'user' => new UserDto(
                id: $i,
                tenant_id: 1000 + ($i % 50),
                name: 'user_' . $i,
                email: "user{$i}@example.com",
                phone: null,
                created_at: '2026-05-19T12:00:00Z',
                is_active: true,
                tags: ['active'],
            ),
            'orders' => $orders,
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Serializer registry. phpser is the subject; igbinary the reference column.
// Each is included only if both its encode and decode functions are loaded,
// so the bench degrades gracefully when an extension is missing.
// ---------------------------------------------------------------------------
$ALL_SERIALIZERS = [
    'phpser'    => ['phpser_serialize',   'phpser_unserialize'],
    'igbinary'  => ['igbinary_serialize', 'igbinary_unserialize'],
    'serialize' => ['serialize',          'unserialize'],
    'msgpack'   => ['msgpack_pack',       'msgpack_unpack'],
];

$SERIALIZERS = [];
foreach ($ALL_SERIALIZERS as $name => [$enc, $dec]) {
    if (function_exists($enc) && function_exists($dec)) {
        $SERIALIZERS[$name] = [$enc, $dec];
    }
}
if (!isset($SERIALIZERS['phpser'])) {
    fwrite(STDERR, "phpser extension not loaded; nothing to benchmark.\n");
    exit(1);
}
$REFERENCE = isset($SERIALIZERS['igbinary']) ? 'igbinary' : 'phpser';

$ITERS = (int) (getenv('BENCH_ITERS') ?: 1000);
$REPS  = max(1, (int) (getenv('BENCH_REPS') ?: 7));
$FORMAT = match (true) {
    in_array('--html', $argv, true),
    in_array('--format=html', $argv, true) => 'html',
    in_array('--format=json', $argv, true) => 'json',
    default => 'text',
};

function median(array $xs): float {
    sort($xs);
    $n = count($xs);
    $m = intdiv($n, 2);
    return ($n % 2) ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2.0;
}

function time_op_sample(callable $fn, $arg, int $iters): float {
    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn($arg);
    }
    return (hrtime(true) - $t) / $iters;
}

function serializer_order(array $names, int $rep): array {
    $count = count($names);
    if ($count < 2) return $names;
    $offset = $rep % $count;
    return array_merge(array_slice($names, $offset), array_slice($names, 0, $offset));
}

// ---------------------------------------------------------------------------
// Correctness gate: phpser must round-trip every shape before we time it.
// ---------------------------------------------------------------------------
$cases = [
    'null'        => null,
    'bool'        => true,
    'int'         => PHP_INT_MIN,
    'float'       => 3.14159,
    'string'      => "hello \x00 binary",
    'rowset_100'  => mk_rowset(100),
    'rowset_1000' => mk_rowset(1000),
    'rowset_distinct_1000' => mk_rowset_distinct(1000),
    'packed_1k'   => mk_numeric_packed(1000),
    'packed_10k'  => mk_numeric_packed(10000),
    'deep_50'     => mk_deep_nested(50),
    'dto_100'     => mk_dto_users(100),
    'dto_1000'    => mk_dto_users(1000),
    'dto_mixed'   => mk_dto_mixed(100),
];

foreach ($cases as $k => $v) {
    $rt = phpser_unserialize(phpser_serialize($v));
    if (serialize($rt) !== serialize($v)) {
        fwrite(STDERR, "ROUND-TRIP MISMATCH: $k\n");
        exit(1);
    }
}

// Timed shapes only (skip the scalar correctness cases).
$timed = array_filter(
    $cases,
    fn($k) => !in_array($k, ['null', 'bool', 'int', 'float', 'string'], true),
    ARRAY_FILTER_USE_KEY
);

// ---------------------------------------------------------------------------
// Measure: results[$shape][$serializer] = ['size','enc','dec'] or ['err'].
// ---------------------------------------------------------------------------
$results = [];
foreach ($timed as $label => $data) {
    $prepared = [];
    foreach ($SERIALIZERS as $name => [$enc, $dec]) {
        try {
            $blob = $enc($data);
            if (!is_string($blob) || $blob === '') {
                throw new RuntimeException('empty payload');
            }
            $dec($blob); // smoke test; a throw drops this cell to n/a
            $prepared[$name] = [$enc, $dec, $blob];
            $results[$label][$name] = [
                'size' => strlen($blob),
                'enc_samples' => [],
                'dec_samples' => [],
            ];
        } catch (\Throwable $e) {
            $results[$label][$name] = ['err' => $e->getMessage()];
        }
    }

    $names = array_keys($prepared);
    for ($rep = 0; $rep < $REPS; $rep++) {
        $order = serializer_order($names, $rep);
        foreach ($order as $name) {
            [$enc, , $blob] = $prepared[$name];
            $results[$label][$name]['enc_samples'][] =
                time_op_sample($enc, $data, $ITERS);
        }
        foreach (array_reverse($order) as $name) {
            [, $dec, $blob] = $prepared[$name];
            $results[$label][$name]['dec_samples'][] =
                time_op_sample($dec, $blob, $ITERS);
        }
    }

    foreach ($names as $name) {
        $results[$label][$name]['enc'] = median(
            $results[$label][$name]['enc_samples']);
        $results[$label][$name]['dec'] = median(
            $results[$label][$name]['dec_samples']);
        unset(
            $results[$label][$name]['enc_samples'],
            $results[$label][$name]['dec_samples']
        );
    }
}

$meta = [
    'php'      => PHP_VERSION,
    'arch'     => php_uname('m'),
    'os'       => php_uname('s') . ' ' . php_uname('r'),
    'iters'    => $ITERS,
    'reps'     => $REPS,
    'date'     => date('Y-m-d'),
    'phpser'   => phpversion('phpser') ?: 'dev',
    'igbinary' => phpversion('igbinary') ?: null,
    'msgpack'  => phpversion('msgpack') ?: null,
];

if ($FORMAT === 'html') {
    render_html($results, array_keys($SERIALIZERS), $REFERENCE, $meta);
} elseif ($FORMAT === 'json') {
    echo json_encode(
        ['meta' => $meta, 'reference' => $REFERENCE, 'results' => $results],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ), "\n";
} else {
    render_text($results, array_keys($SERIALIZERS), $REFERENCE, $meta);
}

// ---------------------------------------------------------------------------
// Renderers
// ---------------------------------------------------------------------------
function fmt_ns(float $ns): string {
    if ($ns >= 1000) return sprintf('%.1fk', $ns / 1000);
    return sprintf('%.0f', $ns);
}

function render_text(array $results, array $serializers, string $ref, array $meta): void {
    printf("phpser bench: PHP %s %s, %d iters, median of %d\n",
        $meta['php'], $meta['arch'], $meta['iters'], $meta['reps']);
    echo "serializers: " . implode(', ', $serializers) . " (reference: $ref)\n";
    echo "round-trip OK\n\n";

    foreach (['size' => 'SIZE (bytes)', 'enc' => 'ENCODE (ns/op)', 'dec' => 'DECODE (ns/op)'] as $metric => $title) {
        echo $title . "\n";
        printf("%-14s", 'shape');
        foreach ($serializers as $s) printf(" | %-16s", $s);
        echo "\n";
        foreach ($results as $shape => $row) {
            printf("%-14s", $shape);
            $base = $row[$ref][$metric] ?? null;
            foreach ($serializers as $s) {
                $cell = $row[$s] ?? ['err' => 'n/a'];
                if (isset($cell['err'])) {
                    printf(" | %-16s", 'n/a');
                    continue;
                }
                $val = $cell[$metric];
                $disp = ($metric === 'size') ? (string) $val : fmt_ns($val);
                if ($base && $s !== $ref) {
                    $pct = ($val - $base) * 100.0 / $base;
                    $disp .= sprintf(' (%+.0f%%)', $pct);
                }
                printf(" | %-16s", $disp);
            }
            echo "\n";
        }
        echo "\n";
    }
}

function render_html(array $results, array $serializers, string $ref, array $meta): void {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

    $metrics = [
        'size' => ['SIZE', 'bytes', false],
        'enc'  => ['ENCODE', 'ns / op', true],
        'dec'  => ['DECODE', 'ns / op', true],
    ];

    // Precompute, per metric+shape, the best (min) value to highlight.
    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>phpser benchmarks</title>
<style>
  :root {
    --bg:#0d1117; --panel:#161b22; --line:#30363d; --txt:#e6edf3;
    --dim:#8b949e; --accent:#3fb950; --accent2:#58a6ff; --bar:#21262d;
    --win:#3fb950; --loss:#f85149; --phpser:#a371f7;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--txt);
    font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .wrap { max-width:1040px; margin:0 auto; padding:40px 24px 80px; }
  h1 { font-size:30px; margin:0 0 6px; letter-spacing:-.5px; }
  h1 .ps { color:var(--phpser); }
  .lede { color:var(--dim); margin:0 0 24px; max-width:70ch; }
  .env { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 28px; }
  .env span { background:var(--panel); border:1px solid var(--line);
    border-radius:6px; padding:4px 10px; font-size:12.5px; color:var(--dim); }
  .env span b { color:var(--txt); font-weight:600; }
  .legend { color:var(--dim); font-size:13px; margin:0 0 28px; }
  .legend .chip { display:inline-block; width:11px; height:11px; border-radius:3px;
    vertical-align:-1px; margin:0 4px 0 14px; }
  section { margin:0 0 40px; }
  h2 { font-size:13px; letter-spacing:1.5px; text-transform:uppercase;
    color:var(--accent2); border-bottom:1px solid var(--line);
    padding-bottom:8px; margin:0 0 4px; }
  h2 small { color:var(--dim); letter-spacing:0; text-transform:none;
    font-size:12px; margin-left:8px; }
  table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
  th, td { text-align:right; padding:9px 10px; border-bottom:1px solid var(--line); }
  th { color:var(--dim); font-weight:600; font-size:12px; }
  th:first-child, td:first-child { text-align:left; }
  td.shape { color:var(--txt); font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:13px; }
  .cell { display:flex; flex-direction:column; align-items:flex-end; gap:3px; }
  .val { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
  .bar { width:100%; max-width:120px; height:5px; background:var(--bar);
    border-radius:3px; overflow:hidden; }
  .bar i { display:block; height:100%; background:var(--accent2); border-radius:3px; }
  td.best .val { color:var(--win); font-weight:700; }
  td.best .bar i { background:var(--win); }
  .ser-phpser { color:var(--phpser) !important; }
  .delta { font-size:11px; color:var(--dim); }
  .delta.win { color:var(--win); }
  .delta.loss { color:var(--loss); }
  .na { color:var(--dim); }
  th.phpser, td.phpser-col { background:rgba(163,113,247,.06); }
  footer { color:var(--dim); font-size:13px; border-top:1px solid var(--line);
    padding-top:20px; margin-top:48px; }
  footer a { color:var(--accent2); text-decoration:none; }
  footer a:hover { text-decoration:underline; }
</style>
</head>
<body>
<div class="wrap">
  <h1><span class="ps">phpser</span> benchmarks</h1>
  <p class="lede">A decoder-optimized binary serializer for read-heavy PHP cache
    workloads, measured against <b>igbinary</b> (the reference), PHP's native
    <b>serialize()</b>, and <b>msgpack</b> across cache-shaped payloads. Lower is
    better on every metric. igbinary is the baseline; each percentage is that
    column's delta vs. igbinary: green beats igbinary, red loses to it.</p>

  <div class="env">
    <span><b>PHP</b> <?=$h($meta['php'])?></span>
    <span><b>arch</b> <?=$h($meta['arch'])?></span>
    <span><b>os</b> <?=$h($meta['os'])?></span>
    <span><b>iters</b> <?=$h($meta['iters'])?></span>
    <span><b>median of</b> <?=$h($meta['reps'])?></span>
    <span><b>phpser</b> <?=$h($meta['phpser'])?></span>
    <?php if ($meta['igbinary']): ?><span><b>igbinary</b> <?=$h($meta['igbinary'])?></span><?php endif; ?>
    <?php if ($meta['msgpack']): ?><span><b>msgpack</b> <?=$h($meta['msgpack'])?></span><?php endif; ?>
    <span><b>date</b> <?=$h($meta['date'])?></span>
  </div>

  <p class="legend">Bars are normalized per row to the largest value (longer =
    slower / bigger).<span class="chip" style="background:var(--win)"></span>best
    in row<span class="chip" style="background:var(--phpser)"></span>phpser column</p>

<?php foreach ($metrics as $metric => [$title, $unit, $isTime]): ?>
  <section>
    <h2><?=$h($title)?><small><?=$h($unit)?>; lower is better</small></h2>
    <table>
      <thead>
        <tr>
          <th>shape</th>
          <?php foreach ($serializers as $s): ?>
            <th class="<?=$s==='phpser'?'phpser':''?>"><?=$h($s)?><?=$s===$ref?' *':''?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($results as $shape => $row):
        $vals = [];
        foreach ($serializers as $s) {
          if (isset($row[$s][$metric])) $vals[$s] = $row[$s][$metric];
        }
        $min = $vals ? min($vals) : 0;
        $max = $vals ? max($vals) : 0;
        $base = $row[$ref][$metric] ?? null;
      ?>
        <tr>
          <td class="shape"><?=$h($shape)?></td>
          <?php foreach ($serializers as $s):
            $cell = $row[$s] ?? ['err' => 'n/a'];
            $isPhpser = $s === 'phpser';
            if (isset($cell['err'])): ?>
            <td class="<?=$isPhpser?'phpser-col ':''?>"><span class="na">n/a</span></td>
            <?php continue; endif;
            $v = $cell[$metric];
            $isBest = abs($v - $min) < 1e-9;
            $w = $max > 0 ? max(4, round($v / $max * 100)) : 0;
            $disp = $isTime ? fmt_ns($v) : number_format($v);
            $delta = '';
            if ($base && $s !== $ref) {
              $pct = ($v - $base) * 100.0 / $base;
              $cls = $pct < -0.5 ? 'win' : ($pct > 0.5 ? 'loss' : '');
              $delta = "<span class='delta $cls'>" . sprintf('%+.0f%%', $pct) . "</span>";
            }
          ?>
            <td class="<?=$isBest?'best ':''?><?=$isPhpser?'phpser-col':''?>">
              <div class="cell">
                <span class="val <?=$isPhpser?'ser-phpser':''?>"><?=$disp?></span>
                <div class="bar"><i style="width:<?=$w?>%"></i></div>
                <?=$delta?>
              </div>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach; ?>

  <footer>
    <p>Reproduce: <code>php -d extension=phpser.so -d extension=igbinary.so -d extension=msgpack.so bench.php --html &gt; docs/index.html</code>.
       igbinary marked <code>*</code> is the reference column.
       Shapes: <code>rowset_*</code> mixed assoc rows, <code>packed_*</code> numeric
       arrays, <code>deep_50</code> nested containers, <code>dto_*</code>
       single-class typed-object batches (Laravel-queue-style).</p>
    <p><a href="https://github.com/iliaal/phpser">github.com/iliaal/phpser</a> ·
       <a href="https://ilia.ws/blog/phpser-a-fast-secure-binary-serializer-for-php-cache-workloads">the writeup</a></p>
  </footer>
</div>
</body>
</html>
<?php
    echo ob_get_clean();
}
