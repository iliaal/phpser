<?php
// ab.php: phpser-only A/B harness for perf candidates. Times encode+decode
// per shape, median of BENCH_REPS, phpser only (no igbinary/msgpack noise).
// Run the SAME script alternately against baseline.so and candidate.so:
//   php -d extension=baseline.so  ab.php
//   php -d extension=candidate.so ab.php
// Knobs: BENCH_ITERS (default 1000), BENCH_REPS (default 15).
//   BENCH_MODE=plain   (default) phpser_serialize / phpser_unserialize
//   BENCH_MODE=signed  phpser_serialize_signed / phpser_unserialize_signed
//   BENCH_MODE=gc      decode BENCH_GC_KEEP (default 200) copies kept alive
//                      with the collector on; prints ns/op and gc runs
//   BENCH_APP=1        appends eloquent_coll_30 and wallet (see bench.php)
// gc mode runs in-process: the adaptive GC threshold drifts across shapes,
// but both binaries replay the same sequence, so the A/B comparison holds.
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

// BENCH_APP=1 fixtures, byte-comparable copies of the bench.php builders.

function distinct_string(string $value): string {
    return substr($value . "\0", 0, strlen($value));
}

function fx_hex(string $seed, int $len): string {
    return substr(hash('sha256', $seed), 0, $len);
}

function fx_uuid7(string $seed): string {
    $h = hash('sha256', $seed);
    return sprintf('%s-%s-7%s-%s-%s',
        substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 16, 4), substr($h, 20, 12));
}

function fx_ts(int $offset): string {
    return gmdate('Y-m-d H:i:s', 1767225600 + $offset);
}

// Mirrors the property surface of an Illuminate model instance as it sits in
// cache: framework defaults (mostly empty arrays / null / false), class-level
// casts/fillable/guarded literals, and attributes === original after a fetch.
class EloquentModelLike {
    protected $connection = 'mysql';
    protected $table = null;
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $with = [];
    protected $withCount = [];
    public $preventsLazyLoading = false;
    protected $perPage = 15;
    public $exists = false;
    public $wasRecentlyCreated = false;
    protected $escapeWhenCastingToString = false;
    protected $attributes = [];
    protected $original = [];
    protected $changes = [];
    protected $previous = [];
    protected $casts = [
        'id' => 'string', 'metadata' => 'array', 'amount' => 'decimal:2',
        'size' => 'integer', 'version' => 'integer', 'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected $classCastCache = [];
    protected $attributeCastCache = [];
    protected $dateFormat = null;
    protected $appends = [];
    protected $dispatchesEvents = [];
    protected $observables = [];
    protected $relations = [];
    protected $touches = [];
    protected $relationAutoloadCallback = null;
    protected $relationAutoloadContext = null;
    public $timestamps = true;
    public $usesUniqueIds = true;
    protected $hidden = [];
    protected $visible = [];
    protected $fillable = [
        'owner_id', 'hash', 'path', 'thumbnail_path', 'original_path', 'metadata',
        'status', 'type', 'amount', 'size', 'version', 'deleted_at', 'expires_at',
        'rejected_reason', 'notes', 'verified_by',
    ];
    protected $guarded = ['id', 'created_at', 'updated_at'];
    protected $forceDeleting = false;
    protected $dates = [];
    protected $hashAlgo = null;
    protected $pivotParent = null;
    protected $morphClass = null;
    protected $lockVersion = null;
    protected $readOnly = false;
    protected $unguarded = false;
    protected $routeKeyName = null;

    public function setRawAttributes(array $attributes): void {
        $this->attributes = $attributes;
        $this->original = $this->attributes;
        $this->exists = true;
    }

    public function getAttributes(): array { return $this->attributes; }
    public function getOriginal(): array { return $this->original; }
}

function mk_eloquent_model(int $i): EloquentModelLike {
    $statuses = ['pending', 'approved', 'rejected'];
    $types = ['license', 'passport', 'certificate', 'transcript'];
    $id = fx_uuid7("doc$i");
    $owner = fx_uuid7('owner' . ($i % 7));
    $m = new EloquentModelLike();
    $m->setRawAttributes([
        'id' => $id,
        'owner_id' => $owner,
        'hash' => hash('sha256', "content$i"),
        'path' => "documents/$owner/$id.pdf",
        'thumbnail_path' => "thumbnails/$owner/$id.png",
        'original_path' => "uploads/tmp/" . fx_hex("up$i", 20) . ".pdf",
        'metadata' => json_encode([
            'mime' => 'application/pdf', 'pages' => 1 + $i % 9,
            'original_name' => "scan_$i.pdf", 'bytes' => 20480 + $i * 131,
        ]),
        'status' => distinct_string($statuses[$i % 3]),
        'type' => distinct_string($types[$i % 4]),
        'created_at' => fx_ts($i * 3607),
        'updated_at' => fx_ts($i * 3607 + 86400),
        'amount' => sprintf('%d.%02d', 100 + $i * 7, $i % 100),
        'size' => 20480 + $i * 131,
        'version' => 1 + $i % 3,
        'deleted_at' => null,
        'expires_at' => null,
        'rejected_reason' => null,
        'notes' => null,
    ]);
    return $m;
}

// Illuminate\Support\Collection keeps its models in a protected $items list.
class CollectionLike {
    protected $items = [];
    protected $escapeWhenCastingToString = false;
    public function __construct(array $items) { $this->items = $items; }
    public function all(): array { return $this->items; }
}

function mk_eloquent_collection(int $n): CollectionLike {
    $items = [];
    for ($i = 0; $i < $n; $i++) $items[] = mk_eloquent_model($i);
    return new CollectionLike($items);
}

// Carbon's own __serialize() payload shape (DateTime-compatible triple).
final class CarbonLike {
    public string $date;
    public int $timezone_type;
    public string $timezone;

    public function __construct(string $date) {
        $this->date = $date;
        $this->timezone_type = 3;
        $this->timezone = 'UTC';
    }

    public function __serialize(): array {
        return ['date' => $this->date, 'timezone_type' => $this->timezone_type, 'timezone' => $this->timezone];
    }

    public function __unserialize(array $data): void {
        $this->date = $data['date'];
        $this->timezone_type = $data['timezone_type'];
        $this->timezone = $data['timezone'];
    }
}

function mk_carbon(int $offset): CarbonLike {
    return new CarbonLike(fx_ts($offset) . sprintf('.%06d', (int) fmod($offset * 7919, 1000000)));
}

enum WalletGroupType: string {
    case Licenses = 'licenses';
    case Certifications = 'certifications';
    case Education = 'education';
    case Identity = 'identity';
    case Employment = 'employment';
}

enum RecordStatus: string {
    case Active = 'active';
    case Expired = 'expired';
    case Pending = 'pending';
}

enum RecordSource: string {
    case Manual = 'manual';
    case PrimarySource = 'primary_source';
    case Import = 'import';
}

enum RecordVisibility: string {
    case Private = 'private';
    case Shared = 'shared';
}

enum RecordKind: int {
    case License = 1;
    case Certificate = 2;
    case Degree = 3;
    case Badge = 4;
}

final class VerificationSource {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url,
        public readonly RecordSource $kind,
        public readonly ?CarbonLike $checkedAt,
    ) {}
}

final class WalletRecord {
    public function __construct(
        public readonly string $id,
        public readonly string $walletId,
        public readonly RecordKind $kind,
        public readonly RecordStatus $status,
        public readonly RecordSource $source,
        public readonly RecordVisibility $visibility,
        public readonly ?CarbonLike $issuedAt,
        public readonly ?CarbonLike $expiresAt,
        public readonly ?CarbonLike $verifiedAt,
        public readonly array $details,
        public readonly string $title,
        public readonly string $issuer,
        public readonly string $number,
        public readonly string $country,
        public readonly ?string $state,
        public readonly ?string $notes,
        public readonly bool $isPrimary,
        public readonly bool $isVerified,
        public readonly bool $isExpired,
        public readonly int $sortOrder,
        public readonly int $documentCount,
        public readonly array $documents,
        public readonly ?VerificationSource $verificationSource,
        public readonly array $metadata,
        public readonly array $tags,
        public readonly string $createdBy,
        public readonly ?float $score,
    ) {}
}

final class WalletGroup {
    public function __construct(
        public readonly WalletGroupType $type,
        public readonly array $records,
        public readonly array $documents,
    ) {}
}
// Records alternate $docsPerRecord / $docsPerRecord-1 documents; the defaults
// (10 groups x 4 records, 100 models) put the native serialize() payload at
// ~390 KB, the size class of the ~372 KB production sample. Every model is referenced twice: from its
// record and from the group's flat document list.
function mk_wallet(int $groups = 10, int $recordsPerGroup = 4, int $docsPerRecord = 3): array {
    $types = WalletGroupType::cases();
    $statuses = RecordStatus::cases();
    $kinds = RecordKind::cases();
    $walletId = fx_uuid7('wallet');
    $source = new VerificationSource(
        fx_uuid7('vsrc'), 'State Licensing Board', 'https://verify.example.gov/lookup',
        RecordSource::PrimarySource, mk_carbon(999),
    );
    $out = [];
    $doc = 0;
    $rec = 0;
    for ($g = 0; $g < $groups; $g++) {
        $groupDocs = [];
        $records = [];
        for ($r = 0; $r < $recordsPerGroup; $r++, $rec++) {
            $recDocs = [];
            $docCount = $docsPerRecord - $rec % 2;
            for ($d = 0; $d < $docCount; $d++, $doc++) {
                $model = mk_eloquent_model($doc);
                $recDocs[] = $model;
                $groupDocs[] = $model;
            }
            $records[] = new WalletRecord(
                id: fx_uuid7("rec$rec"),
                walletId: $walletId,
                kind: $kinds[$rec % count($kinds)],
                status: $statuses[$rec % count($statuses)],
                source: $rec % 2 ? RecordSource::PrimarySource : RecordSource::Manual,
                visibility: $rec % 5 ? RecordVisibility::Shared : RecordVisibility::Private,
                issuedAt: mk_carbon($rec * 86400),
                expiresAt: $rec % 3 ? mk_carbon($rec * 86400 + 31536000) : null,
                verifiedAt: $rec % 2 ? mk_carbon($rec * 86400 + 3600) : null,
                details: [distinct_string('Board certified'), "Class " . chr(65 + $rec % 4), "Renewal " . (2027 + $rec % 3)],
                title: "Registered Nurse License $rec",
                issuer: 'State Board of Nursing',
                number: sprintf('RN-%07d', 40000 + $rec * 17),
                country: distinct_string('US'),
                state: $rec % 4 ? distinct_string('CA') : null,
                notes: $rec % 6 ? null : "Renewed after audit $rec",
                isPrimary: $r === 0,
                isVerified: $rec % 2 === 1,
                isExpired: false,
                sortOrder: $r,
                documentCount: $docCount,
                documents: $recDocs,
                verificationSource: $rec % 2 ? $source : null,
                metadata: ['imported' => false, 'rev' => $rec % 4, 'origin' => distinct_string('portal')],
                tags: [],
                createdBy: fx_uuid7('user' . ($rec % 3)),
                score: $rec % 3 ? 0.5 + ($rec % 10) / 20 : null,
            );
        }
        $out[] = new WalletGroup($types[$g % count($types)], $records, $groupDocs);
    }
    return $out;
}

$ITERS = (int)(getenv('BENCH_ITERS') ?: 1000);
$REPS  = max(1, (int)(getenv('BENCH_REPS') ?: 15));
$MODE  = getenv('BENCH_MODE') ?: 'plain';
$KEY   = str_repeat("\x5a", 32);

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

// Retained decode with the collector on: every result stays reachable, so
// the gc runs counted here are root-buffer scans, not garbage collection.
function med_retained(string $blob, int $keep, int $r): array {
    $s = [];
    $runs = [];
    for ($k = 0; $k < $r; $k++) {
        gc_collect_cycles();
        $before = gc_status()['runs'];
        $bag = [];
        $t = hrtime(true);
        for ($i = 0; $i < $keep; $i++) $bag[] = phpser_unserialize($blob);
        $s[] = (hrtime(true) - $t) / $keep;
        $runs[] = gc_status()['runs'] - $before;
        unset($bag);
    }
    sort($s);
    sort($runs);
    return [$s[intdiv($r, 2)], $runs[intdiv($r, 2)]];
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
if (getenv('BENCH_APP')) {
    $shapes['eloquent_coll_30'] = mk_eloquent_collection(30);
    $shapes['wallet'] = mk_wallet();
}

if ($MODE === 'gc') {
    ini_set('memory_limit', '-1');
    gc_enable();
    $keep = max(10, (int)(getenv('BENCH_GC_KEEP') ?: 200));
    foreach ($shapes as $name => $data) {
        $blob = phpser_serialize($data);
        [$dec, $runs] = med_retained($blob, $keep, $REPS);
        printf("%-12s size=%7d keep=%4d dec_gc=%9.1f gc_runs=%d\n", $name, strlen($blob), $keep, $dec, $runs);
    }
    exit(0);
}

$enc_fn = 'phpser_serialize';
$dec_fn = 'phpser_unserialize';
if ($MODE === 'signed') {
    $enc_fn = fn($v) => phpser_serialize_signed($v, $KEY);
    $dec_fn = fn($b) => phpser_unserialize_signed($b, $KEY);
} elseif ($MODE !== 'plain') {
    fwrite(STDERR, "unknown BENCH_MODE=$MODE (plain|signed|gc)\n");
    exit(1);
}

foreach ($shapes as $name => $data) {
    $blob = $enc_fn($data);
    if (serialize($dec_fn($blob)) !== serialize($data)) {
        fwrite(STDERR, "MISMATCH $name\n");
        exit(1);
    }
    $enc = med($enc_fn, $data, $ITERS, $REPS);
    $dec = med($dec_fn, $blob, $ITERS, $REPS);
    printf("%-12s size=%7d enc=%9.1f dec=%9.1f\n", $name, strlen($blob), $enc, $dec);
}
