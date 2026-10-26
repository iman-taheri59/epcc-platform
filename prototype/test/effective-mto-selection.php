<?php

declare(strict_types=1);

require __DIR__.'/../domain/EffectiveMtoSelection.php';

use Epcc\Prototype\EffectiveMtoSelection;

$policy = new EffectiveMtoSelection();
$paths = ['H1' => ['ROOT' => ['ROOT'], 'A' => ['ROOT', 'A'], 'B' => ['ROOT', 'B']],
    'H2' => ['ROOT' => ['ROOT'], 'A' => ['ROOT', 'A'], 'B' => ['ROOT', 'B']]];
function snap(string $id, string $doc, int $order, int $qty = 100000, string $scope = 'A'): array
{
    return ['id' => $id, 'project' => 'P1', 'document' => $doc, 'revision' => 'rev-'.$id,
        'state' => 'ACCEPTED', 'hierarchy' => 'H1', 'acceptance_order' => $order,
        'accepted_at' => '2026-10-18T10:00:00Z', 'coverage' => [$scope],
        'rows' => [['id' => 'row-'.$id, 'scope' => $scope, 'material' => 'MAT1',
            'unit' => 'M', 'quantity_milli' => $qty]]];
}
function evidence(array $ids): array
{
    return ['project' => 'P1', 'batch_ids' => $ids, 'reason' => 'Independent additive quantities',
        'controlled_evidence_id' => 'ADMITTED-CDE-REV-1'];
}
$n = 0;
$check = function (string $name, array $snapshots, callable $assert, array $ev = [], ?array $hierarchy = null) use (&$n, $policy, $paths): void {
    $original = serialize([$snapshots, $ev, $hierarchy]);
    $r = $policy->select('P1', $snapshots, $hierarchy ?? $paths, $ev);
    if (!$assert($r) || $original !== serialize([$snapshots, $ev, $hierarchy])) {
        throw new RuntimeException($name.': '.json_encode($r));
    }
    echo 'PASS '.(++$n).' '.$name."\n";
};
$blocked = static fn (string $code): Closure => static fn (array $r): bool => !$r['ready']
    && $r['findings'] === [$code] && $r['totals_milli'] === null && $r['selected_rows'] === [];
$a = snap('A1', 'D1', 1);
$b = snap('A2', 'D1', 2, 110000);
$c = snap('B1', 'D2', 3, 20000, 'B');
$check('100 then110 is110 not210; tied timestamps', [$a, $b], fn ($r) => $r['totals_milli']['MAT1']['M'] === 110000 && $r['selected_rows'] === ['row-A2']);
$check('input permutation deterministic', [$b, $a], fn ($r) => $r === $policy->select('P1', [$a, $b], $paths));
$x = $b; $x['rows'][0]['material'] = 'MAT2';
$check('removed material does not survive revision replacement', [$a, $x], fn ($r) => !isset($r['totals_milli']['MAT1']) && $r['totals_milli']['MAT2']['M'] === 110000);
$check('disjoint leaf documents add', [$a, $c], fn ($r) => $r['totals_milli']['MAT1']['M'] === 120000);
$x = snap('B1', 'D2', 3, 20000);
$check('same physical scope is not additive by default', [$a, $x], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'));
$check('exact admitted batch pair permits additivity', [$a, $x], fn ($r) => $r['totals_milli']['MAT1']['M'] === 120000, [evidence(['B1', 'A1'])]);
$check('evidence not inherited by next revision', [$a, $b, $x], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'), [evidence(['A1', 'B1'])]);
$parent = snap('C1', 'D3', 4, 50000, 'ROOT');
$check('parent descendant overlap', [$a, $parent], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'));
$check('all overlapping pairs need evidence', [$a, $x, $parent], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'), [evidence(['A1', 'B1'])]);
$ev = evidence(['A1', 'B1']); unset($ev['controlled_evidence_id']); $ev['hash'] = str_repeat('a', 64);
$check('hash alone is not admitted evidence', [$a, $x], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'), [$ev]);
$ev = evidence(['A1', 'B1']); $ev['project'] = 'P2';
$check('foreign evidence cannot authorize additivity', [$a, $x], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'), [$ev]);
$x = $c; $x['hierarchy'] = 'H2';
$check('cross version needs separate mapping design even with evidence', [$a, $x], $blocked('CROSS_VERSION_SCOPE_UNRESOLVED'), [evidence(['A1', 'B1'])]);
$x = $b; $x['coverage'] = ['B']; $x['rows'][0]['scope'] = 'B';
$check('scope set change cannot erase or carry history implicitly', [$a, $x], $blocked('SCOPE_CHANGE_REQUIRES_EXPLICIT_DISPOSITION'));
$x = $a; $x['hierarchy'] = '';
$check('missing hierarchy is unresolved not project wide', [$x], $blocked('INVALID_SNAPSHOT'));
$x = $a; $x['project'] = 'P2';
$check('foreign project rejected', [$x], $blocked('PROJECT_MISMATCH'));
$x = $b; $x['acceptance_order'] = 1;
$check('timestamp tie requires unique serialized sequence', [$a, $x], $blocked('AMBIGUOUS_ACCEPTANCE_ORDER'));
$x = $a; $x['state'] = 'DRAFT';
$check('draft never enters accepted demand', [$x], $blocked('NOT_ACCEPTED'));
$x = $c; $x['rows'][0]['unit'] = 'EA';
$check('M and EA are separate totals', [$a, $x], fn ($r) => $r['totals_milli']['MAT1'] === ['EA' => 20000, 'M' => 100000]);
$x = $a; $x['rows'][0]['quantity_milli'] = 0.1;
$check('float quantities rejected', [$x], $blocked('INVALID_QUANTITY'));
$x = $a; $x['rows'][0]['scope'] = 'B';
$check('row outside coverage rejected', [$x], $blocked('ROW_OUTSIDE_COVERAGE'));
$x = $a; $x['rows'][0]['material'] = '';
$check('unresolved material blocks instead of disappearing', [$x], $blocked('UNRESOLVED_MATERIAL_OR_UNIT'));
$x = $c; $x['rows'][0]['id'] = $a['rows'][0]['id'];
$check('duplicate immutable row rejected', [$a, $x], $blocked('DUPLICATE_OR_MISSING_ROW'));
$check('pinned hierarchy path must preserve ancestor prefix', [$parent], $blocked('INVALID_HIERARCHY'), [], ['H1' => ['ROOT' => ['BAD', 'ROOT']]]);
$x = $a; $x['coverage'] = [];
$check('coverage cannot be inferred from absent rows', [$x], $blocked('MISSING_COVERAGE'));
$x = $a; $x['rows'][0]['quantity_milli'] = PHP_INT_MAX;
$check('integer overflow is a finding not a float total', [$x, $c], $blocked('QUANTITY_OVERFLOW'));
$x = $a; $x['rows'][0]['quantity_milli'] = 1;
$check('0.001 quantities kept without tolerance rounding', [$x], fn ($r) => $r['totals_milli']['MAT1']['M'] === 1);
$check('empty accepted universe explicitly ready and empty', [], fn ($r) => $r['ready'] && $r['totals_milli'] === []);
$x = $a; $x['rows'] = [];
$check('empty batch does not clear demand implicitly', [$x], $blocked('EMPTY_ACCEPTED_BATCH'));
$x = $b; $x['revision'] = $a['revision'];
$check('one immutable accepted batch per revision', [$a, $x], $blocked('DUPLICATE_REVISION'));
$x = $a; $x['coverage'][] = 'B';
$check('empty scope needs explicit disposition', [$x], $blocked('EMPTY_SCOPE_REQUIRES_DISPOSITION'));
$x = $a; $x['rows'][0]['quantity_milli'] = -1;
$check('negative gross rejected', [$x], $blocked('INVALID_QUANTITY'));
$x = $a; $x['accepted_at'] = '2099-01-01';
$check('wall clock cannot override serialized acceptance order', [$x, $b], fn ($r) => $r['selected_batches'] === ['A2']);
$x = $c; $x['coverage'] = ['A', 'B'];
$x['rows'][] = ['id' => 'row-B1-A', 'scope' => 'A', 'material' => 'MAT2', 'unit' => 'M', 'quantity_milli' => 1000];
$check('one overlapping segment blocks whole combined report', [$a, $x], $blocked('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE'));
echo "OK $n executable design-contract cases (not Laravel integration tests).\n";
