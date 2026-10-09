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
//              BENCH_REPS  (timed repetitions, median+IQR reported, default 35),
//              BENCH_WARMUP (untimed warmup iters per op, default 100, 0 disables).
//
// --extended appends groups after the default table (--only-extended skips
// the default table; both work with text, --html, and
// --format=json; the default table itself is unchanged):
//   shapes       single values, ['data' => ..., 'ttl' => 60] envelopes, rowsets
//                whose tags arrays are heap-distinct or one shared heap array
//   app          Laravel-app shapes (Eloquent model/collection, Carbon, wallet,
//                queue job, catalog, JWKS); reference is native serialize()
//   signed       phpser_*_signed vs igbinary/serialize framed with hash_hmac()
//   gc_retained  decode N copies kept alive with the collector ON vs OFF;
//                reports automatic gc runs (needs pcntl for per-sample forks)
// Extended knobs: BENCH_EXT_REPS (default min(BENCH_REPS, 15)),
//   BENCH_EXT_SAMPLE_MS (per-sample budget used to size inner loops, default
//   40, capped at BENCH_ITERS), BENCH_GC_REPS (default 5), BENCH_GC_KEEP
//   (default 200), BENCH_GC_MEM_MB (retained-set budget, default 256).
//
// Opcache: production runs with opcache, which stores script literals as
// shared-memory interned strings and immutable arrays. To measure that
// configuration:
//   php -d zend_extension=opcache -d opcache.enable_cli=1 \
//       -d extension=./modules/phpser.so -d extension=igbinary.so bench.php --extended
// (drop the zend_extension flag when opcache is already loaded by php.ini).

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
    $rowCount = count($out);
    for ($i = 0; $i < $rowCount; $i++) {
        $out[$i]['created_at'] = distinct_string($out[$i]['created_at']);
        $tagCount = count($out[$i]['tags']);
        for ($j = 0; $j < $tagCount; $j++) {
            $out[$i]['tags'][$j] = distinct_string($out[$i]['tags'][$j]);
        }
    }
    return $out;
}

function mk_numeric_packed(int $n): array {
    return range(0, $n - 1);
}

// Unsorted ints at realistic magnitudes. range() shapes collapse to a
// constant-size affine run, so this is the shape that keeps the linear
// per-element integer path measured. Fixed seed keeps payload bytes stable.
function mk_numeric_rand(int $n): array {
    mt_srand(42);
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = mt_rand(0, 1000000);
    return $out;
}

function mk_deep_nested(int $depth): array {
    $cur = ['leaf' => 42];
    for ($i = 0; $i < $depth; $i++) {
        $cur = ['next' => $cur, 'i' => $i];
    }
    return $cur;
}

// DTO batches measure class lookup and property handling absent from array rowsets.

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

// Interleave two classes to exercise mixed-class lookup.
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

// --extended fixtures. Everything below feeds only the --extended groups; the
// default table above never touches it.

// Rebuilds a list element by element so the result is a refcounted heap array
// rather than the immutable literal (array_values() would return the input).
function distinct_list(array $src): array {
    $out = [];
    foreach ($src as $v) $out[] = $v;
    return $out;
}

function mk_rowset_tags_distinct(int $rows): array {
    $out = mk_rowset($rows);
    foreach ($out as $i => $row) $out[$i]['tags'] = distinct_list($row['tags']);
    return $out;
}

function mk_rowset_tags_shared(int $rows): array {
    $out = mk_rowset($rows);
    $shared = distinct_list(['a', 'b', 'c']);
    foreach ($out as $i => $row) $out[$i]['tags'] = $shared;
    return $out;
}

function mk_small_assoc(): array {
    return [
        'id' => 42, 'name' => distinct_string('alice'), 'email' => distinct_string('alice@example.com'),
        'active' => true, 'score' => 1.5, 'roles' => distinct_list(['admin', 'editor']),
    ];
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

enum AuditEvent: string {
    case Viewed = 'viewed';
    case Shared = 'shared';
    case Downloaded = 'downloaded';
}

final class AuditDto {
    public function __construct(
        public readonly AuditEvent $event,
        public readonly string $actorId,
        public readonly string $subjectId,
        public readonly string $tenantId,
        public readonly array $context,
        public readonly array $changes,
        public readonly string $userAgent,
        public readonly CarbonLike $occurredAt,
        public readonly string $ipAddress,
        public readonly string $url,
        public readonly string $method,
        public readonly string $requestId,
        public readonly int $severity,
    ) {}
}

// Queue payload shape: Queueable defaults around a private readonly DTO.
final class QueueJobLike {
    public $connection = null;
    public $queue = null;
    public $delay = null;
    public $afterCommit = null;
    public $middleware = [];
    public $chained = [];
    public $chainConnection = null;
    public $chainQueue = null;
    public $chainCatchCallbacks = null;
    public $job = null;

    public function __construct(private readonly AuditDto $dto) {}

    public function dto(): AuditDto { return $this->dto; }
}

function mk_queue_job(): QueueJobLike {
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    return new QueueJobLike(new AuditDto(
        event: AuditEvent::Shared,
        actorId: fx_uuid7('actor'),
        subjectId: fx_uuid7('subject'),
        tenantId: fx_uuid7('tenant'),
        context: ['share_id' => fx_uuid7('share'), 'recipient' => 'hr@example.com', 'expires_in' => 604800, 'records' => 3, 'channel' => distinct_string('email')],
        changes: ['status' => distinct_string('shared'), 'shared_at' => fx_ts(7200), 'count' => 4],
        userAgent: distinct_string($ua),
        occurredAt: mk_carbon(7200),
        ipAddress: distinct_string('203.0.113.42'),
        url: 'https://app.example.com/wallet/share/' . fx_hex('share', 16),
        method: distinct_string('POST'),
        requestId: fx_uuid7('request'),
        severity: 2,
    ));
}

function mk_catalog(int $rows = 60): array {
    $kinds = ['date', 'string', 'email', 'numeric'];
    $out = [];
    for ($r = 0; $r < $rows; $r++) {
        $attrs = [];
        $n = 10 + ($r * 7) % 16;
        for ($a = 0; $a < $n; $a++) {
            $validators = [];
            $validators[] = 'nullable';
            $validators[] = $kinds[($r + $a) % 4];
            $attrs[] = [
                'label' => ucfirst(str_replace('_', ' ', "field_{$a}_label")),
                'name' => "field_$a",
                'validators' => $validators,
                'isHidden' => $a % 5 === 4,
            ];
        }
        $out[] = ['document_type' => "doc_type_$r", 'label' => "Document Type $r", 'attributes' => $attrs];
    }
    return $out;
}

function mk_jwks(): array {
    $keys = [];
    for ($k = 0; $k < 2; $k++) {
        $raw = '';
        for ($b = 0; strlen($raw) < 256; $b++) $raw .= hash('sha256', "modulus$k-$b", true);
        $keys[] = [
            'alg' => 'RS256',
            'e' => 'AQAB',
            'kid' => fx_hex("kid$k", 40),
            'kty' => 'RSA',
            'n' => rtrim(strtr(base64_encode(substr($raw, 0, 256)), '+/', '-_'), '='),
            'use' => 'sig',
        ];
    }
    return ['keys' => $keys];
}

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
$REPS  = max(1, (int) (getenv('BENCH_REPS') ?: 35));
$warmup_env = getenv('BENCH_WARMUP');
// The string "0" is an explicit opt-out, not a missing environment value.
$WARMUP_ITERS = max(0, (int) (($warmup_env === false || $warmup_env === '') ? 100 : $warmup_env));
$FORMAT = match (true) {
    in_array('--html', $argv, true),
    in_array('--format=html', $argv, true) => 'html',
    in_array('--format=json', $argv, true) => 'json',
    default => 'text',
};
$ONLY_EXTENDED = in_array('--only-extended', $argv, true);
$EXTENDED = $ONLY_EXTENDED || in_array('--extended', $argv, true);

function median(array $xs): float {
    sort($xs);
    $n = count($xs);
    $m = intdiv($n, 2);
    return ($n % 2) ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2.0;
}

function percentile_sorted(array $sorted, float $q): float {
    $n = count($sorted);
    if ($n === 1) return $sorted[0];
    $pos = ($n - 1) * $q;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);
    return $lo === $hi ? $sorted[$lo] : $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
}

function iqr(array $xs): float {
    sort($xs);
    return percentile_sorted($xs, 0.75) - percentile_sorted($xs, 0.25);
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
    'packed_rand_10k' => mk_numeric_rand(10000),
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

$distinctRowCount = count($cases['rowset_distinct_1000']);
for ($i = 0; $i < $distinctRowCount; $i++) {
    if (ReflectionReference::fromArrayElement($cases['rowset_distinct_1000'], $i) !== null) {
        fwrite(STDERR, "ROWSET DISTINCT FIXTURE CONTAINS REFERENCES\n");
        exit(1);
    }
}

$timed = array_filter(
    $cases,
    fn($k) => !in_array($k, ['null', 'bool', 'int', 'float', 'string'], true),
    ARRAY_FILTER_USE_KEY
);

// A Closure case is a fixture builder: each serializer then encodes its own
// copy. Encoding an object can build its property table (igbinary_serialize()
// does on 8.4; native serialize() does not), and phpser's output for
// protected/untyped properties differs once that table exists, so a shared
// copy would measure phpser in a state that only the other columns created.
function case_data(mixed $case): mixed {
    return $case instanceof Closure ? $case() : $case;
}

// $iters is either one count for every case or a per-label map.
function bench_matrix(array $cases, array $serializers, int|array $iters, int $reps, int $warmup): array {
    $results = [];
    foreach ($cases as $label => $case) {
        $n = is_array($iters) ? $iters[$label] : $iters;
        $prepared = [];
        foreach ($serializers as $name => [$enc, $dec]) {
            try {
                $data = case_data($case);
                $blob = $enc($data);
                if (!is_string($blob) || $blob === '') {
                    throw new RuntimeException('empty payload');
                }
                $dec($blob); // smoke test; a throw drops this cell to n/a
                $prepared[$name] = [$enc, $dec, $blob, $data];
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

        // Untimed warmup so cold caches / first-call paths don't pollute rep 0.
        if ($warmup > 0) {
            foreach ($prepared as [$enc, $dec, $blob, $data]) {
                time_op_sample($enc, $data, $warmup);
                time_op_sample($dec, $blob, $warmup);
            }
        }
        for ($rep = 0; $rep < $reps; $rep++) {
            $order = serializer_order($names, $rep);
            foreach ($order as $name) {
                [$enc, , $blob, $data] = $prepared[$name];
                $results[$label][$name]['enc_samples'][] =
                    time_op_sample($enc, $data, $n);
            }
            foreach (array_reverse($order) as $name) {
                [, $dec, $blob] = $prepared[$name];
                $results[$label][$name]['dec_samples'][] =
                    time_op_sample($dec, $blob, $n);
            }
        }

        foreach ($names as $name) {
            $results[$label][$name]['enc'] = median(
                $results[$label][$name]['enc_samples']);
            $results[$label][$name]['dec'] = median(
                $results[$label][$name]['dec_samples']);
            $results[$label][$name]['enc_iqr'] = iqr(
                $results[$label][$name]['enc_samples']);
            $results[$label][$name]['dec_iqr'] = iqr(
                $results[$label][$name]['dec_samples']);
            unset(
                $results[$label][$name]['enc_samples'],
                $results[$label][$name]['dec_samples']
            );
        }
    }
    return $results;
}

// Picks an inner-loop count per case so one sample of the slowest serializer
// costs about $budget_ns; large app payloads would otherwise take minutes.
function calibrate_iters(array $cases, array $serializers, int $cap, float $budget_ns): array {
    $out = [];
    foreach ($cases as $label => $case) {
        $worst = 1.0;
        foreach ($serializers as [$enc, $dec]) {
            try {
                $data = case_data($case);
                $blob = $enc($data);
                $worst = max($worst, time_op_sample($enc, $data, 3), time_op_sample($dec, $blob, 3));
            } catch (\Throwable) {
            }
        }
        $out[$label] = max(min(10, $cap), min($cap, (int) ($budget_ns / $worst)));
    }
    return $out;
}

function hmac_frame(string $payload, string $key): string {
    return hash_hmac('sha256', $payload, $key, true) . $payload;
}

function hmac_open(string $frame, string $key): string {
    $payload = substr($frame, 32);
    if (!hash_equals(substr($frame, 0, 32), hash_hmac('sha256', $payload, $key, true))) {
        throw new RuntimeException('signature verification failed');
    }
    return $payload;
}

// Decodes $keep copies into a live array so every result stays reachable,
// the way a request that loads cached collections holds them. With the
// collector on, any possible roots left behind by decode fill the root buffer
// and trigger full-graph scans that the GC-off timing does not pay.
function gc_retained_sample(callable $dec, string $blob, int $keep, bool $gc_on): array {
    ini_set('memory_limit', '-1');
    gc_enable();
    for ($i = 0; $i < 3; $i++) $dec($blob);
    gc_collect_cycles();
    if (!$gc_on) gc_disable();
    $before = gc_status();
    $bag = [];
    $t = hrtime(true);
    for ($i = 0; $i < $keep; $i++) $bag[] = $dec($blob);
    $ns = (hrtime(true) - $t) / $keep;
    $after = gc_status();
    return [
        'ns' => $ns,
        'runs' => $after['runs'] - $before['runs'],
        'collected' => $after['collected'] - $before['collected'],
        'threshold' => $before['threshold'],
    ];
}

// The GC root threshold only ever adapts upward within a process, so a cell
// measured late would see fewer collector runs than one measured early. Each
// sample runs in a forked child of a parent that has kept the collector off,
// which starts every sample from the same threshold.
function run_isolated(callable $fn): array {
    if (!function_exists('pcntl_fork')) {
        try {
            return $fn() + ['isolated' => false];
        } catch (\Throwable $e) {
            return ['err' => $e->getMessage(), 'isolated' => false];
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'phpser-bench-');
    $pid = pcntl_fork();
    if ($pid === -1) {
        unlink($path);
        throw new RuntimeException('pcntl_fork failed');
    }
    if ($pid === 0) {
        try {
            file_put_contents($path, serialize($fn()));
        } catch (\Throwable $e) {
            file_put_contents($path, serialize(['err' => $e->getMessage()]));
        }
        exit(0);
    }
    pcntl_waitpid($pid, $status);
    $raw = file_get_contents($path);
    unlink($path);
    $res = $raw === '' || $raw === false ? false : unserialize($raw);
    if (!is_array($res)) {
        throw new RuntimeException('forked sample exited without a result');
    }
    return $res + ['isolated' => true];
}

function bench_gc_retained(array $cases, array $serializers, int $keep_cap, int $mem_budget, int $reps): array {
    $results = [];
    foreach ($cases as $label => $case) {
        $blobs = [];
        $bytes = 1;
        foreach ($serializers as $name => [$enc, $dec]) {
            try {
                $blobs[$name] = $enc(case_data($case));
                $m0 = memory_get_usage();
                $probe = $dec($blobs[$name]);
                $bytes = max($bytes, memory_get_usage() - $m0);
                unset($probe);
            } catch (\Throwable $e) {
                $results[$label][$name] = ['err' => $e->getMessage()];
            }
        }
        $keep = max(10, min($keep_cap, intdiv($mem_budget, $bytes)));
        $names = array_keys(array_diff_key($blobs, $results[$label] ?? []));
        $samples = [];
        for ($rep = 0; $rep < $reps; $rep++) {
            foreach (serializer_order($names, $rep) as $name) {
                if (isset($results[$label][$name]['err'])) continue;
                $dec = $serializers[$name][1];
                foreach (['gc_on' => true, 'gc_off' => false] as $mode => $on) {
                    $sample = run_isolated(
                        fn() => gc_retained_sample($dec, $blobs[$name], $keep, $on));
                    if (isset($sample['err'])) {
                        $results[$label][$name] = ['err' => $sample['err']];
                        continue 2;
                    }
                    $samples[$name][$mode][] = $sample;
                }
            }
        }
        foreach ($names as $name) {
            if (isset($results[$label][$name]['err'])) continue;
            $cell = ['keep' => $keep, 'isolated' => $samples[$name]['gc_on'][0]['isolated']];
            foreach (['gc_on', 'gc_off'] as $mode) {
                $ns = array_column($samples[$name][$mode], 'ns');
                $cell[$mode] = median($ns);
                $cell[$mode . '_iqr'] = iqr($ns);
            }
            $cell['gc_on_runs'] = (int) median(array_column($samples[$name]['gc_on'], 'runs'));
            $cell['gc_on_collected'] = (int) median(array_column($samples[$name]['gc_on'], 'collected'));
            $cell['threshold'] = $samples[$name]['gc_on'][0]['threshold'];
            $results[$label][$name] = $cell;
        }
    }
    return $results;
}

function run_extended(array $serializers, string $reference, int $iters, int $reps, int $warmup): array {
    $ext_reps = max(1, (int) (getenv('BENCH_EXT_REPS') ?: min($reps, 15)));
    $budget_ns = 1e6 * max(1, (int) (getenv('BENCH_EXT_SAMPLE_MS') ?: 40));
    $gc_reps = max(1, (int) (getenv('BENCH_GC_REPS') ?: 5));
    $gc_keep = max(10, (int) (getenv('BENCH_GC_KEEP') ?: 200));
    $gc_mem = 1048576 * max(16, (int) (getenv('BENCH_GC_MEM_MB') ?: 256));

    $dto_1000 = fn() => mk_dto_users(1000);
    $rowset_1000 = mk_rowset(1000);
    $one_dto = fn() => mk_dto_users(1)[0];
    $string_15 = distinct_string('cache:user:4217');

    $groups = [];
    $groups['shapes'] = [
        'title' => 'EXTENDED SHAPES',
        'note' => 'single values, wrapped envelopes, array-sharing variants',
        'reference' => $reference,
        'cases' => [
            'one_dto'            => $one_dto,
            'small_assoc'        => mk_small_assoc(),
            'string_15'          => $string_15,
            'int_single'         => 1234567,
            'wrapped_dto_1000'   => fn() => ['data' => mk_dto_users(1000), 'ttl' => 60],
            'wrapped_rowset_1000' => ['data' => $rowset_1000, 'ttl' => 60],
            'rowset_tags_distinct_1000' => mk_rowset_tags_distinct(1000),
            'rowset_tags_shared_1000'   => mk_rowset_tags_shared(1000),
        ],
        'serializers' => $serializers,
    ];
    $groups['app'] = [
        'title' => 'APP SHAPES',
        'note' => 'Laravel-app payload shapes, dependency-free builders; reference is native serialize()',
        'reference' => isset($serializers['serialize']) ? 'serialize' : $reference,
        'cases' => [
            'eloquent_model'   => fn() => mk_eloquent_model(0),
            'eloquent_coll_30' => fn() => mk_eloquent_collection(30),
            'carbon_like'      => fn() => mk_carbon(0),
            'wallet'           => fn() => mk_wallet(),
            'queue_job'        => fn() => mk_queue_job(),
            'catalog'          => fn() => mk_catalog(),
            'jwks'             => fn() => mk_jwks(),
        ],
        'serializers' => $serializers,
    ];

    $key = str_repeat("\x5a", 32);
    $signed = [
        'phpser_signed' => [
            fn($v) => phpser_serialize_signed($v, $key),
            fn($b) => phpser_unserialize_signed($b, $key),
        ],
    ];
    if (isset($serializers['igbinary'])) {
        $signed['igbinary+hmac'] = [
            fn($v) => hmac_frame(igbinary_serialize($v), $key),
            fn($b) => igbinary_unserialize(hmac_open($b, $key)),
        ];
    }
    $signed['serialize+hmac'] = [
        fn($v) => hmac_frame(serialize($v), $key),
        fn($b) => unserialize(hmac_open($b, $key)),
    ];
    $groups['signed'] = [
        'title' => 'SIGNED (HMAC-SHA256)',
        'note' => 'every column runs through a closure; +hmac columns frame hash_hmac() || payload and verify with hash_equals()',
        'reference' => isset($signed['igbinary+hmac']) ? 'igbinary+hmac' : 'serialize+hmac',
        'cases' => [
            'dto_1000'    => $dto_1000,
            'rowset_1000' => $rowset_1000,
            'one_dto'     => $one_dto,
            'string_15'   => $string_15,
        ],
        'serializers' => $signed,
    ];

    foreach ($groups as $group) {
        foreach ($group['cases'] as $label => $case) {
            $v = case_data($case);
            if (serialize(phpser_unserialize(phpser_serialize($v))) !== serialize($v)) {
                throw new RuntimeException("ROUND-TRIP MISMATCH: $label");
            }
        }
    }
    foreach ($groups['signed']['cases'] as $label => $case) {
        $v = case_data($case);
        if (serialize(phpser_unserialize_signed(phpser_serialize_signed($v, $key), $key)) !== serialize($v)) {
            throw new RuntimeException("SIGNED ROUND-TRIP MISMATCH: $label");
        }
    }

    foreach ($groups as $id => $group) {
        $group_iters = calibrate_iters($group['cases'], $group['serializers'], $iters, $budget_ns);
        $groups[$id]['iters'] = $group_iters;
        $groups[$id]['reps'] = $ext_reps;
        $groups[$id]['results'] = bench_matrix($group['cases'], $group['serializers'], $group_iters, $ext_reps, $warmup);
        $groups[$id]['serializers'] = array_keys($group['serializers']);
        unset($groups[$id]['cases']);
    }

    $gc_cases = [
        'dto_1000'         => $dto_1000,
        'dto_mixed'        => fn() => mk_dto_mixed(100),
        'rowset_1000'      => $rowset_1000,
        'eloquent_coll_30' => fn() => mk_eloquent_collection(30),
        'wallet'           => fn() => mk_wallet(),
    ];
    $gc_results = bench_gc_retained($gc_cases, $serializers, $gc_keep, $gc_mem, $gc_reps);
    $isolated = true;
    foreach ($gc_results as $row) {
        foreach ($row as $cell) $isolated = $isolated && ($cell['isolated'] ?? true);
    }
    $groups['gc_retained'] = [
        'title' => 'GC-ON RETAINED DECODE',
        'note' => sprintf('decode N copies kept alive, ns/op; rN = automatic gc runs during the N decodes; median of %d %s samples; N capped at %d and by %d MiB',
            $gc_reps, $isolated ? 'forked' : 'in-process (no pcntl: GC threshold drifts across cells)',
            $gc_keep, intdiv($gc_mem, 1048576)),
        'reference' => $reference,
        'serializers' => array_keys($serializers),
        'reps' => $gc_reps,
        'results' => $gc_results,
    ];
    return $groups;
}

$results = [];
$extended = [];
// GC pauses are noise, not signal: collect once, then hold the collector
// off while timing so a cycle run can't land inside one rep's samples.
gc_collect_cycles();
gc_disable();
try {
    if (!$ONLY_EXTENDED) {
        $results = bench_matrix($timed, $SERIALIZERS, $ITERS, $REPS, $WARMUP_ITERS);
    }
    if ($EXTENDED) {
        $extended = run_extended($SERIALIZERS, $REFERENCE, $ITERS, $REPS, $WARMUP_ITERS);
    }
} finally {
    gc_enable();
}

$meta = [
    'php'      => PHP_VERSION,
    'arch'     => php_uname('m'),
    'os'       => php_uname('s') . ' ' . php_uname('r'),
    'iters'    => $ITERS,
    'reps'     => $REPS,
    'warmup_iters' => $WARMUP_ITERS,
    'date'     => date('Y-m-d'),
    'phpser'   => phpversion('phpser') ?: 'dev',
    'igbinary' => phpversion('igbinary') ?: null,
    'msgpack'  => phpversion('msgpack') ?: null,
];
if ($EXTENDED) {
    $meta['opcache'] = function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);
}

if ($FORMAT === 'html') {
    render_html($results, array_keys($SERIALIZERS), $REFERENCE, $meta, $extended);
} elseif ($FORMAT === 'json') {
    $doc = ['meta' => $meta, 'reference' => $REFERENCE, 'results' => $results];
    if ($EXTENDED) $doc['extended'] = $extended;
    echo json_encode($doc, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} else {
    if (!$ONLY_EXTENDED) render_text($results, array_keys($SERIALIZERS), $REFERENCE, $meta);
    render_text_extended($extended, $meta);
}

function fmt_ns(float $ns): string {
    if ($ns >= 1000) return sprintf('%.1fk', $ns / 1000);
    return sprintf('%.0f', $ns);
}

function render_text(array $results, array $serializers, string $ref, array $meta): void {
    printf("phpser bench: PHP %s %s, %d iters, median of %d (warmup %d, GC off, ±IQR spread)\n",
        $meta['php'], $meta['arch'], $meta['iters'], $meta['reps'], $meta['warmup_iters']);
    echo "serializers: " . implode(', ', $serializers) . " (reference: $ref)\n";
    echo "round-trip OK\n\n";

    render_text_tables($results, $serializers, $ref, [
        'size' => ['SIZE (bytes)', 16],
        'enc'  => ['ENCODE (ns/op)', 22],
        'dec'  => ['DECODE (ns/op)', 22],
    ], 14);
}

// $metrics: metric => [title, column width]. 'size' prints raw bytes; every
// other metric is ns with its _iqr spread, plus rN when a _runs count exists.
function render_text_tables(array $results, array $serializers, string $ref, array $metrics, int $shape_w): void {
    foreach ($metrics as $metric => [$title, $w]) {
        echo $title . "\n";
        printf("%-{$shape_w}s", 'shape');
        foreach ($serializers as $s) printf(" | %-{$w}s", $s);
        echo "\n";
        foreach ($results as $shape => $row) {
            printf("%-{$shape_w}s", $shape);
            $base = $row[$ref][$metric] ?? null;
            foreach ($serializers as $s) {
                $cell = $row[$s] ?? ['err' => 'n/a'];
                if (isset($cell['err'])) {
                    printf(" | %-{$w}s", 'n/a');
                    continue;
                }
                $val = $cell[$metric];
                $disp = ($metric === 'size') ? (string) $val : fmt_ns($val);
                if ($metric !== 'size') {
                    $disp .= ' ±' . fmt_ns($cell[$metric . '_iqr'] ?? 0);
                }
                if (isset($cell[$metric . '_runs'])) {
                    $disp .= ' r' . $cell[$metric . '_runs'];
                }
                if ($base && $s !== $ref) {
                    $pct = ($val - $base) * 100.0 / $base;
                    $disp .= sprintf(' (%+.0f%%)', $pct);
                }
                printf(" | %-{$w}s", $disp);
            }
            echo "\n";
        }
        echo "\n";
    }
}

function extended_metrics(string $id): array {
    if ($id === 'gc_retained') {
        return [
            'gc_on'  => ['GC-ON RETAINED DECODE (ns/op)', 28],
            'gc_off' => ['GC-OFF RETAINED DECODE (ns/op, same N)', 28],
        ];
    }
    return [
        'size' => ['SIZE (bytes)', 16],
        'enc'  => ['ENCODE (ns/op)', 22],
        'dec'  => ['DECODE (ns/op)', 22],
    ];
}

function extended_shape_label(string $id, string $shape, array $row): string {
    if ($id !== 'gc_retained') return $shape;
    foreach ($row as $cell) {
        if (isset($cell['keep'])) return sprintf('%s N=%d', $shape, $cell['keep']);
    }
    return $shape;
}

function render_text_extended(array $groups, array $meta): void {
    if (!$groups) return;
    printf("== extended (opcache %s) ==\n\n", !empty($meta['opcache']) ? 'on' : 'off');
    foreach ($groups as $id => $g) {
        printf("## %s: %s (reference: %s, median of %d)\n", $g['title'], $g['note'], $g['reference'], $g['reps']);
        if (isset($g['iters'])) {
            $parts = [];
            foreach ($g['iters'] as $shape => $n) $parts[] = "$shape=$n";
            echo "iters: " . implode(' ', $parts) . "\n";
        }
        echo "\n";
        $rows = extended_rows($id, $g);
        $shape_w = max(14, ...array_map('strlen', array_keys($rows)));
        render_text_tables($rows, $g['serializers'], $g['reference'], extended_metrics($id), $shape_w);
    }
}

function extended_rows(string $id, array $group): array {
    $rows = [];
    foreach ($group['results'] as $shape => $row) $rows[extended_shape_label($id, $shape, $row)] = $row;
    return $rows;
}

function extended_html_metrics(string $id): array {
    if ($id === 'gc_retained') {
        return [
            'gc_on'  => ['GC-ON RETAINED DECODE', 'ns / op, rN = gc runs', true],
            'gc_off' => ['GC-OFF RETAINED DECODE', 'ns / op, same N', true],
        ];
    }
    return [
        'size' => ['SIZE', 'bytes', false],
        'enc'  => ['ENCODE', 'ns / op', true],
        'dec'  => ['DECODE', 'ns / op', true],
    ];
}

function render_html(array $results, array $serializers, string $ref, array $meta, array $extended = []): void {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

    $metrics = [
        'size' => ['SIZE', 'bytes', false],
        'enc'  => ['ENCODE', 'ns / op', true],
        'dec'  => ['DECODE', 'ns / op', true],
    ];

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
    <span><b>warmup</b> <?=$h($meta['warmup_iters'])?></span>
    <span><b>phpser</b> <?=$h($meta['phpser'])?></span>
    <?php if ($meta['igbinary']): ?><span><b>igbinary</b> <?=$h($meta['igbinary'])?></span><?php endif; ?>
    <?php if ($meta['msgpack']): ?><span><b>msgpack</b> <?=$h($meta['msgpack'])?></span><?php endif; ?>
    <span><b>date</b> <?=$h($meta['date'])?></span>
  </div>

  <p class="legend">Bars are normalized per row to the largest value (longer =
    slower / bigger).<span class="chip" style="background:var(--win)"></span>best
    in row<span class="chip" style="background:var(--phpser)"></span>phpser column</p>

<?php html_metric_sections($results, $serializers, $ref, $metrics, $h); ?>
<?php foreach ($extended as $gid => $g): ?>
  <p class="lede"><b><?=$h($g['title'])?></b>: <?=$h($g['note'])?>; reference column <b><?=$h($g['reference'])?></b>, median of <?=$h($g['reps'])?>.</p>
<?php html_metric_sections(extended_rows($gid, $g), $g['serializers'], $g['reference'], extended_html_metrics($gid), $h); ?>
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

function html_metric_sections(array $results, array $serializers, string $ref, array $metrics, callable $h): void {
    ?>
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
            if ($isTime) {
                $disp .= ' ±' . fmt_ns($cell[$metric . '_iqr'] ?? 0);
            }
            if (isset($cell[$metric . '_runs'])) {
                $disp .= ' r' . $cell[$metric . '_runs'];
            }
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
<?php
}
