<?php

declare(strict_types=1);
require __DIR__.'/../domain/MtoScopeAdmissionProposal.php';
use Epcc\Prototype\MtoScopeAdmissionProposal;

function fixture(): array {
    $binding = ['project_id' => 'P1', 'batch_id' => 'B1', 'observation_id' => 'O1',
        'payload_hash' => str_repeat('a',64), 'source_rows_hash' => str_repeat('b',64),
        'hierarchy_revision_id' => 'H1', 'header_scope_id' => null];
    $observed = $binding + ['rows' => [
        ['id' => 'R1', 'scope_id' => 'S1', 'hierarchy_revision_id' => 'H1'],
        ['id' => 'R2', 'scope_id' => 'S2', 'hierarchy_revision_id' => 'H1'],
    ]];
    $node = fn ($id,$kind,$parent) => ['id' => $id,'kind' => $kind,'parent_id' => $parent,'project_id' => 'P1'];
    $evidence = ['record_id' => 'SYNTHETIC-E1','issuer' => 'Synthetic issuer','number' => 'TEST-1',
        'revision' => 'A','date' => '2026-10-18','retained_uri' => 'synthetic://retained/E1','file_sha256' => str_repeat('c',64)];
    $proposal = ['schema_version' => 1, 'source_binding' => $binding,
        'nodes' => [$node('P1','PROJECT',null),$node('F1','FACILITY','P1'),$node('S1','SUBSYSTEM','F1'),
            $node('A1','AREA','F1'),$node('S2','SUBSYSTEM','A1')],
        'coverage' => [['scope_id'=>'S1','row_ids'=>['R1']],['scope_id'=>'S2','row_ids'=>['R2']]],
        'topology_evidence' => $evidence,'coverage_evidence' => $evidence];
    return [$proposal,$observed];
}
$v = new MtoScopeAdmissionProposal(); $n=0;
$test = function (string $name, callable $edit, ?string $code = null) use ($v,&$n): void {
    [$p,$o]=fixture(); $edit($p,$o); $before=serialize([$p,$o]); $r=$v->assess($p,$o);
    $expected = $code === null ? $r['structurally_reviewable'] : in_array($code,$r['findings'],true) && !$r['structurally_reviewable'];
    if (!$expected || $before!==serialize([$p,$o]) || $r['ready_to_admit']!==false || $r['ready']!==false
        || $r['gross']!==null || $r['admission_state']!=='UNADMITTED' || $r['writes_performed']!==0
        || count($r['unverified_requirements'])!==8) { throw new RuntimeException($name.': '.json_encode($r)); }
    echo 'PASS '.(++$n).' '.$name."\n";
};
$test('complete proposal remains unadmitted', fn (&$p,&$o)=>null);
$test('node and coverage input order irrelevant', function (&$p,&$o) { $p['nodes']=array_reverse($p['nodes']);$p['coverage']=array_reverse($p['coverage']);$o['rows']=array_reverse($o['rows']); });
$test('unknown approval field cannot be smuggled', function (&$p,&$o) {$p['admission_state']='ADMITTED';}, 'PROPOSAL_SCHEMA_INVALID');
$test('schema version strict integer', function (&$p,&$o) {$p['schema_version']='1';}, 'PROPOSAL_SCHEMA_INVALID');
foreach (['project_id','batch_id','observation_id','payload_hash','source_rows_hash','hierarchy_revision_id'] as $key) {
    $test('exact binding '.$key, function (&$p,&$o) use ($key) {$p['source_binding'][$key]='OTHER';}, 'SOURCE_BINDING_MISSING_OR_MISMATCHED');
}
$test('missing pin never inferred', function (&$p,&$o) {$p['source_binding']['hierarchy_revision_id']=null;$o['hierarchy_revision_id']=null;}, 'SOURCE_BINDING_MISSING_OR_MISMATCHED');
$test('header binding exact including null', function (&$p,&$o) {$p['source_binding']['header_scope_id']='S1';}, 'HEADER_BINDING_INVALID');
$test('hash format is not arbitrary text', function (&$p,&$o) {$p['source_binding']['payload_hash']=$o['payload_hash']='not-a-hash';}, 'SOURCE_DIGEST_INVALID');
$test('foreign topology node', function (&$p,&$o) {$p['nodes'][2]['project_id']='P2';}, 'FOREIGN_NODE');
$test('duplicate node rejected', function (&$p,&$o) {$p['nodes'][]=$p['nodes'][2];}, 'DUPLICATE_NODE');
$test('missing root rejected', function (&$p,&$o) {array_shift($p['nodes']);}, 'EXACT_PROJECT_ROOT_REQUIRED');
$test('missing parent rejected', function (&$p,&$o) {$p['nodes'][2]['parent_id']='UNKNOWN';}, 'PARENT_NOT_IN_MANIFEST');
$test('cycle rejected', function (&$p,&$o) {$p['nodes'][1]['parent_id']='S1';}, 'TOPOLOGY_CYCLE');
$test('wrong parent kind rejected', function (&$p,&$o) {$p['nodes'][2]['parent_id']='P1';}, 'PARENT_KIND_INVALID');
$test('null area represented by direct facility edge', function (&$p,&$o) {$p['nodes'][4]['parent_id']='F1';});
$test('coverage is not derived from material rows', function (&$p,&$o) {unset($p['coverage']);}, 'EXPLICIT_COVERAGE_REQUIRED');
$test('parent coverage not silently expanded', function (&$p,&$o) {$p['coverage'][0]['scope_id']='F1';}, 'COVERAGE_MUST_REFERENCE_EXPLICIT_LEAF');
$test('omitted row rejected', function (&$p,&$o) {array_pop($p['coverage']);}, 'OBSERVED_ROWS_NOT_FULLY_COVERED');
$test('duplicate covered row rejected', function (&$p,&$o) {$p['coverage'][0]['row_ids'][]='R1';}, 'ROW_COVERED_MORE_THAN_ONCE');
$test('unknown row rejected', function (&$p,&$o) {$p['coverage'][0]['row_ids'][]='RX';}, 'UNKNOWN_COVERED_ROW');
$test('row assigned outside scope rejected', function (&$p,&$o) {$p['coverage'][0]['row_ids']=['R2'];}, 'ROW_OUTSIDE_DECLARED_SCOPE');
$test('duplicate covered scope rejected', function (&$p,&$o) {$p['coverage'][]=$p['coverage'][0];}, 'DUPLICATE_COVERED_SCOPE');
$test('empty scope is not approved zero', function (&$p,&$o) {$p['coverage'][0]['row_ids']=[];}, 'EMPTY_SCOPE_REQUIRES_SEPARATE_DISPOSITION');
$test('null row scope not project root', function (&$p,&$o) {$o['rows'][0]['scope_id']=null;}, 'OBSERVED_ROW_ID_OR_SCOPE_MISSING');
$test('duplicate observed row rejected', function (&$p,&$o) {$o['rows'][]=$o['rows'][0];}, 'DUPLICATE_OBSERVED_ROW');
$test('row pin differs from bound version', function (&$p,&$o) {$o['rows'][0]['hierarchy_revision_id']='H2';}, 'ROW_PIN_MISMATCH_OR_MISSING');
$test('header mismatch requires disposition', function (&$p,&$o) {$p['source_binding']['header_scope_id']=$o['header_scope_id']='S1';}, 'HEADER_BOUNDARY_NOT_PROVEN');
$test('hash alone is not controlled record declaration', function (&$p,&$o) {$p['topology_evidence']=['file_sha256'=>str_repeat('a',64)];}, 'CONTROLLED_RECORD_DECLARATION_REQUIRED');
$test('record issuer required', function (&$p,&$o) {$p['coverage_evidence']['issuer']=' ';}, 'CONTROLLED_RECORD_DECLARATION_INCOMPLETE');
$test('invalid calendar date rejected', function (&$p,&$o) {$p['coverage_evidence']['date']='2026-02-30';}, 'CONTROLLED_RECORD_DATE_OR_DIGEST_INVALID');
$test('embedded null date fails closed without exception', function (&$p,&$o) {$p['coverage_evidence']['date']="2026-10-18\0";}, 'CONTROLLED_RECORD_DATE_OR_DIGEST_INVALID');
$test('malformed nested input diagnosed', function (&$p,&$o) {$p['nodes']=[null,42,[]];$p['coverage']=[false];$o['rows']=[null];}, 'NODE_INVALID');
$test('empty source not retirement', function (&$p,&$o) {$o['rows']=[];}, 'OBSERVED_ROWS_REQUIRED');
$test('facility header permits proven direct and area descendants', function (&$p,&$o) {
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='F1';
});
$test('project header permits proven descendants structurally only', function (&$p,&$o) {
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='P1';
});
$test('area header permits its descendant', function (&$p,&$o) {
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='A1';
    $p['coverage']=[$p['coverage'][1]];$o['rows']=[$o['rows'][1]];
});
$test('same explicit leaf header remains valid', function (&$p,&$o) {
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='S1';
    $p['coverage']=[$p['coverage'][0]];$o['rows']=[$o['rows'][0]];
});
$test('sibling facility does not establish ancestry', function (&$p,&$o) {
    $p['nodes'][]=['id'=>'F2','project_id'=>'P1','kind'=>'FACILITY','parent_id'=>'P1'];
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='F2';
}, 'HEADER_BOUNDARY_NOT_PROVEN');
$test('absent header node is unresolved not guessed', function (&$p,&$o) {
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='MISSING';
}, 'HEADER_BOUNDARY_NOT_PROVEN');
$test('cycle cannot hang ancestry traversal', function (&$p,&$o) {
    $p['nodes'][1]['parent_id']='S1';
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='A1';
}, 'TOPOLOGY_CYCLE');
$test('descendant proposal with mismatched row version still blocked', function (&$p,&$o) {
    $p['source_binding']['header_scope_id']=$o['header_scope_id']='F1';
    $o['rows'][0]['hierarchy_revision_id']='H2';
}, 'ROW_PIN_MISMATCH_OR_MISSING');
echo "OK $n offline admission-proposal cases; no authentication, authority admission or database writes.\n";
