<?php
// ab.php — phpser-only A/B harness for perf candidates. Times encode+decode
// per shape, median of BENCH_REPS, phpser only (no igbinary/msgpack noise).
// Run the SAME script alternately against baseline.so and candidate.so:
//   php -d extension=baseline.so  ab.php
//   php -d extension=candidate.so ab.php
// Knobs: BENCH_ITERS (default 1000), BENCH_REPS (default 15).
declare(strict_types=1);

function mk_rowset(int $rows): array {
    $out = [];
    for ($i = 0; $i < $rows; $i++) $out[] = [
        'id' => $i, 'user_id' => 1000 + ($i % 50), 'name' => 'row_' . $i,
        'created_at' => '2026-05-19T12:00:00Z', 'amount' => $i * 1.07,
        'active' => ($i % 3) === 0, 'tags' => ['a', 'b', 'c'],
    ];
    return $out;
}
function mk_numeric_packed(int $n): array { return range(0, $n - 1); }
function mk_numeric_rand(int $n): array {
    mt_srand(42);
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = mt_rand(0, 1000000);
    return $out;
}
function mk_deep_nested(int $depth): array {
    $cur = ['leaf' => 42];
    for ($i = 0; $i < $depth; $i++) $cur = ['next' => $cur, 'i' => $i];
    return $cur;
}
final class UserDto {
    public function __construct(
        public int $id, public int $tenant_id, public string $name,
        public string $email, public ?string $phone, public string $created_at,
        public bool $is_active, public array $tags,
    ) {}
}
final class OrderDto {
    public function __construct(
        public int $id, public int $user_id, public string $sku,
        public float $amount, public string $currency, public string $status,
        public ?string $shipped_at,
    ) {}
}
function mk_dto_users(int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = new UserDto(
        id: $i, tenant_id: 1000 + ($i % 50), name: 'user_' . $i,
        email: "user{$i}@example.com",
        phone: ($i % 3 === 0) ? null : '+1-555-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
        created_at: '2026-05-19T12:00:00Z', is_active: ($i % 7) !== 0,
        tags: ['active', 'verified', 'beta'],
    );
    return $out;
}
function mk_dto_mixed(int $users): array {
    $out = [];
    for ($i = 0; $i < $users; $i++) {
        $orders = [];
        for ($j = 0; $j < 3; $j++) $orders[] = new OrderDto(
            id: $i * 10 + $j, user_id: $i, sku: 'SKU-' . ($i * 10 + $j),
            amount: ($i + $j) * 12.99, currency: 'USD',
            status: ['pending', 'shipped', 'delivered'][$j % 3],
            shipped_at: $j === 0 ? null : '2026-05-19T12:00:00Z',
        );
        $out[] = ['user' => new UserDto(
            id: $i, tenant_id: 1000 + ($i % 50), name: 'user_' . $i,
            email: "user{$i}@example.com", phone: null,
            created_at: '2026-05-19T12:00:00Z', is_active: true, tags: ['active'],
        ), 'orders' => $orders];
    }
    return $out;
}

$ITERS = (int)(getenv('BENCH_ITERS') ?: 1000);
$REPS  = max(1, (int)(getenv('BENCH_REPS') ?: 15));

function med(callable $fn, $arg, int $it, int $r): float {
    $s = [];
    for ($k = 0; $k < $r; $k++) {
        $t = hrtime(true);
        for ($i = 0; $i < $it; $i++) $fn($arg);
        $s[] = (hrtime(true) - $t) / $it;
    }
    sort($s);
    return $s[intdiv($r, 2)];
}

$shapes = [
    'rowset_100'  => mk_rowset(100),
    'rowset_1000' => mk_rowset(1000),
    'packed_1k'   => mk_numeric_packed(1000),
    'packed_10k'  => mk_numeric_packed(10000),
    'packed_rand_10k' => mk_numeric_rand(10000),
    'deep_50'     => mk_deep_nested(50),
    'dto_100'     => mk_dto_users(100),
    'dto_1000'    => mk_dto_users(1000),
    'dto_mixed'   => mk_dto_mixed(100),
];

foreach ($shapes as $name => $data) {
    $blob = phpser_serialize($data);
    // round-trip guard
    if (serialize(phpser_unserialize($blob)) !== serialize($data)) {
        fwrite(STDERR, "MISMATCH $name\n");
        exit(1);
    }
    $enc = med('phpser_serialize', $data, $ITERS, $REPS);
    $dec = med('phpser_unserialize', $blob, $ITERS, $REPS);
    printf("%-12s size=%7d enc=%9.1f dec=%9.1f\n", $name, strlen($blob), $enc, $dec);
}
