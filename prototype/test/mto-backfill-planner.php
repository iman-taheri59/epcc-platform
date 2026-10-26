<?php

declare(strict_types=1);
require __DIR__.'/../domain/MtoBackfillPlanner.php';
use Epcc\Prototype\MtoBackfillPlanner;
$p = new MtoBackfillPlanner();
function batch(string $id, string $doc = 'D1', string $time = '2026-10-18T10:00:00.000000Z'): array {
    return ['id' => $id, 'project' => 'P1', 'document' => $doc, 'revision' => 'REV-'.$id, 'state' => 'ACCEPTED',
        'accepted_at_utc' => $time, 'hierarchy' => 'H1', 'coverage' => ['S1'],
        'scope_manifest_ref' => 'CONTROLLED-MANIFEST', 'topology_ref' => 'CONTROLLED-TOPOLOGY',
        'rows' => [['id' => 'ROW-'.$id, 'scope' => 'S1', 'quantity' => '100.000', 'unit' => 'M']]];
}
function review(MtoBackfillPlanner $p, array $items, array $order = ['A', 'B']): array {
    return ['project' => 'P1', 'inventory_sha256' => $p->fingerprint($items), 'documents' => [
        'D1' => ['batch_order' => $order, 'reason' => 'Retained acceptance records reviewed', 'controlled_evidence_ref' => 'CONTROLLED-ORDER-RECORD']]];
}
function has(array $r, string $code): bool { return in_array($code, array_column($r['findings'], 'code'), true); }
$n = 0;
$test = function (string $name, array $items, array $review, callable $check) use ($p, &$n): void {
    $before = serialize([$items, $review]); $r = $p->assess('P1', $items, $review);
    if (!$check($r) || $r['ready_to_apply'] !== false || $r['writes_performed'] !== 0 || $before !== serialize([$items, $review])
        || ($r['status'] === 'BLOCKED' && ($r['document_chains'] !== [] || $r['candidate_sha256'] !== null))) {
        throw new RuntimeException($name.': '.json_encode($r));
    }
    echo 'PASS '.(++$n).' '.$name."\n";
};
$a = batch('A'); $b = batch('B'); $items = [$a, $b]; $r = review($p, $items);
$test('timestamp tie has no UUID winner', $items, [], fn ($x) => has($x, 'EXPLICIT_DOCUMENT_ORDER_REVIEW_REQUIRED') && count($x['notices']) === 1);
$x = $b; $x['accepted_at_utc'] = '2026-10-19T10:00:00.000000Z';
$test('unique timestamps still require controlled review', [$a, $x], [], fn ($x) => has($x, 'EXPLICIT_DOCUMENT_ORDER_REVIEW_REQUIRED'));
$test('complete bound order becomes review candidate only', $items, $r, fn ($x) => $x['status'] === 'CANDIDATE_FOR_CONTROLLED_REVIEW' && $x['document_chains']['D1']['batch_order'] === ['A','B']);
$test('order may not be guessed from timestamp or lexical ID', $items, review($p, $items, ['B','A']), fn ($x) => $x['document_chains']['D1']['batch_order'] === ['B','A']);
$x = $r; $x['inventory_sha256'] = str_repeat('0',64);
$test('stale inventory rejected', $items, $x, fn ($x) => has($x, 'STALE_OR_FOREIGN_REVIEW_PACKAGE'));
$x = $a; $x['rows'][0]['quantity'] = '110.000';
$test('row content change invalidates reviewed fingerprint', [$x,$b], $r, fn ($x) => has($x, 'STALE_OR_FOREIGN_REVIEW_PACKAGE'));
$x = $r; $x['project'] = 'P2';
$test('foreign review rejected', $items, $x, fn ($x) => has($x, 'STALE_OR_FOREIGN_REVIEW_PACKAGE'));
$x = $a; $x['project'] = 'P2';
$test('foreign batch rejected', [$x,$b], [], fn ($x) => has($x, 'PROJECT_MISMATCH'));
$test('missing order member rejected', $items, review($p,$items,['A']), fn ($x) => has($x, 'ORDER_MUST_COVER_EXACT_BATCH_SET_ONCE'));
$test('duplicate order member rejected', $items, review($p,$items,['A','A','B']), fn ($x) => has($x, 'ORDER_MUST_COVER_EXACT_BATCH_SET_ONCE'));
$test('unknown order member rejected', $items, review($p,$items,['A','X']), fn ($x) => has($x, 'ORDER_MUST_COVER_EXACT_BATCH_SET_ONCE'));
$x = $r; unset($x['documents']['D1']['controlled_evidence_ref']); $x['documents']['D1']['hash'] = str_repeat('a',64);
$test('hash alone not an order evidence reference', $items, $x, fn ($x) => has($x, 'ORDER_EVIDENCE_REQUIRED'));
$x = $a; $x['hierarchy'] = null;
$test('missing hierarchy not guessed', [$x,$b], [], fn ($x) => has($x, 'MISSING_HIERARCHY'));
$x = $a; unset($x['scope_manifest_ref']);
$test('row set not a full scope manifest', [$x,$b], [], fn ($x) => has($x, 'MISSING_SCOPE_MANIFEST_REF'));
$x = $a; unset($x['topology_ref']);
$test('current hierarchy is not historical topology proof', [$x,$b], [], fn ($x) => has($x, 'MISSING_TOPOLOGY_REF'));
$x = $b; $x['coverage'] = ['S2']; $x['rows'][0]['scope'] = 'S2'; $xx = [$a,$x];
$test('scope set change blocks even with order reference', $xx, review($p,$xx), fn ($x) => has($x, 'SCOPE_CHANGE_REQUIRES_DISPOSITION'));
$x = $b; $x['hierarchy'] = 'H2'; $xx = [$a,$x];
$test('hierarchy revision change is not implicit mapping', $xx, review($p,$xx), fn ($x) => has($x, 'SCOPE_CHANGE_REQUIRES_DISPOSITION'));
$x = $a; $x['rows'][0]['scope'] = null;
$test('missing row scope remains unresolved', [$x,$b], [], fn ($x) => has($x, 'UNRESOLVED_ROW_SCOPE'));
$x = $a; $x['accepted_at_utc'] = '2026-02-30T10:00:00.000000Z';
$test('invalid calendar date rejected', [$x,$b], [], fn ($x) => has($x, 'INVALID_ACCEPTANCE_TIMESTAMP'));
$x = $a; $x['state'] = 'DRAFT';
$test('draft is not legacy accepted data', [$x,$b], [], fn ($x) => has($x, 'NOT_ACCEPTED'));
$test('batch duplicate rejected', [$a,$a], [], fn ($x) => has($x, 'INVALID_OR_DUPLICATE_BATCH'));
$x = $b; $x['revision'] = $a['revision'];
$test('duplicate accepted revision rejected', [$a,$x], [], fn ($x) => has($x, 'DUPLICATE_ACCEPTED_REVISION'));
$x = $a; $x['rows'] = [];
$test('empty batch not interpreted as retirement', [$x,$b], [], fn ($x) => has($x, 'EMPTY_OR_MISSING_ACCEPTED_ROWS'));
$x = $r; $x['documents']['UNKNOWN'] = $x['documents']['D1'];
$test('unknown document in review rejected', $items, $x, fn ($x) => has($x, 'UNKNOWN_DOCUMENT_IN_REVIEW'));
$test('reordered inventory yields same fingerprint and candidate', [$b,$a], $r, fn ($x) => $x === $p->assess('P1',$items,$r));
$test('even empty inventory does not authorize apply', [], [], fn ($x) => $x['status'] === 'CANDIDATE_FOR_CONTROLLED_REVIEW');
echo "OK $n offline backfill-planner cases; no database writes or production migration.\n";
