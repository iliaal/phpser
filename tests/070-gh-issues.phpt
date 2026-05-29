--TEST--
phpser: shapes from recent php-src GitHub issues (gh12265 / gh15169 / gh19701)
--EXTENSIONS--
phpser
--FILE--
<?php

// --- gh12265: cloning an object breaks serialization recursion (variation).
// The original bug was about `r:N` offsets going stale after clone; for us
// the analog is that identity ids must reflect the actual object graph
// post-clone, not pre-clone. ---
class GH_A {
    public function __construct(public GH_B $x) {}
}
class GH_B {
    public GH_A $a;
    public function __serialize(): array { return ['a' => new GH_A($this)]; }
    public function __unserialize(array $d): void { $this->a = $d['a']; }
}
$b1 = new GH_B();
$b2 = new GH_B();  // fresh, distinct instance
$rt1 = phpser_unserialize(phpser_serialize($b1));
$rt2 = phpser_unserialize(phpser_serialize($b2));
$ok = $rt1 instanceof GH_B && $rt1->a instanceof GH_A && $rt1->a->x === $rt1
   && $rt2 instanceof GH_B && $rt2->a instanceof GH_A && $rt2->a->x === $rt2
   && $rt1 !== $rt2;
echo $ok ? "gh12265 OK\n" : "gh12265 FAIL\n";

// Same shape wrapped in an outer class (gh12265 case "C"):
class GH_C { public GH_B $b; public function __construct() { $this->b = new GH_B(); } }
$rt = phpser_unserialize(phpser_serialize(new GH_C()));
$ok = $rt instanceof GH_C
   && $rt->b instanceof GH_B
   && $rt->b->a instanceof GH_A
   && $rt->b->a->x === $rt->b;
echo $ok ? "gh12265_wrapped OK\n" : "gh12265_wrapped FAIL\n";

// --- gh15169: stack overflow on serializing a deep linked list.
// PHP cap is zend.max_allowed_stack_size; our encoder has its own
// MAX_DEPTH=512. Build a chain longer than that and verify the encoder
// rejects it loudly (throws) rather than emitting a truncated payload.
// The encode and decode caps are equal, so a truncated payload would be
// undecodable (decode returns NULL in full) — silent total data loss.
// Fail loud at encode instead. ---
class Node { public ?Node $next = null; }
$first = new Node();
$node = $first;
for ($i = 0; $i < 5000; $i++) {  // exceeds MAX_DEPTH
    $node->next = new Node();
    $node = $node->next;
}
try {
    phpser_serialize($first);  // must throw — not crash, not truncate
    echo "gh15169_no_crash FAIL (no throw)\n";
} catch (\Exception $e) {
    echo str_contains($e->getMessage(), "maximum nesting depth")
        ? "gh15169_no_crash OK\n" : "gh15169_no_crash FAIL: {$e->getMessage()}\n";
}

// And a chain just under the cap round-trips cleanly:
$first = new Node();
$node = $first;
for ($i = 0; $i < 100; $i++) {
    $node->next = new Node();
    $node = $node->next;
}
$rt = phpser_unserialize(phpser_serialize($first));
$probe = $rt; $depth = 0;
while ($probe instanceof Node && $depth < 200) { $probe = $probe->next; $depth++; }
echo ($depth === 101) ? "gh15169_under_cap OK\n" : "gh15169_under_cap FAIL d=$depth\n";

// --- gh19701: serialize loses some data — clone + alias preserved
// across both copies' presence in the same outer array.
// $data = [clone $base, $base] — PHP must serialize both with their
// internal cycles intact; the clone's cycle is distinct from the
// original's cycle. ---
#[\AllowDynamicProperties]
class Item19701 {
    public $children = [];
    public $parent = null;
}
$baseProduct = new Item19701();
$child = new Item19701();
$child->parent = $baseProduct;
$baseProduct->children = [$child];

$data = [clone $baseProduct, $baseProduct];
$rt = phpser_unserialize(phpser_serialize($data));

// rt[1] is the original $baseProduct (back-ref); rt[0] is the clone.
// PHP semantics: `clone` is shallow, so clone->children[0] is the SAME
// $child instance as $baseProduct->children[0]. And $child->parent points
// at the ORIGINAL $baseProduct (set before the clone). So:
//   rt[0]->children[0] === rt[1]->children[0]   (shared $child)
//   rt[0]->children[0]->parent === rt[1]         (parent is original)
//   rt[0] !== rt[1]                              (distinct objects)
$ok = is_array($rt) && count($rt) === 2
   && $rt[0] instanceof Item19701
   && $rt[1] instanceof Item19701
   && $rt[0] !== $rt[1]
   && count($rt[0]->children) === 1
   && count($rt[1]->children) === 1
   && $rt[0]->children[0] === $rt[1]->children[0]
   && $rt[0]->children[0]->parent === $rt[1];
echo $ok ? "gh19701 OK\n" : "gh19701 FAIL\n";

?>
--EXPECT--
gh12265 OK
gh12265_wrapped OK
gh15169_no_crash OK
gh15169_under_cap OK
gh19701 OK
