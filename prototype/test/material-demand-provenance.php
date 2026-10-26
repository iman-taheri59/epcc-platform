<?php

declare(strict_types=1);

require __DIR__.'/../domain/MaterialDemandProvenance.php';

use Epcc\Prototype\MaterialDemandProvenance;

$service = new MaterialDemandProvenance;
$basis = ['project'=>'P1','version'=>'SEL-1','ready'=>true,'rows'=>[
    ['id'=>'R1','project'=>'P1','batch'=>'B1','revision'=>'REV1','scope'=>'S1','hierarchy'=>'H1','material'=>'MAT1','unit'=>'M','quantity_milli'=>10000],
]];
$mr = ['project'=>'P1','id'=>'MR1','version'=>'MRV1','lines'=>[['id'=>'L1','scope'=>'S1','material'=>'MAT1','unit'=>'M','quantity_milli'=>4000]]];
$entry = ['id'=>'E1','project'=>'P1','basis_version'=>'SEL-1','row_id'=>'R1','mr_id'=>'MR1','mr_version'=>'MRV1','mr_line_id'=>'L1','quantity_milli'=>4000];
$legacy = ['id'=>'OLD1','project'=>'P1','basis_version'=>'SEL-1','row_id'=>'R1','quantity_milli'=>6000,'state'=>'COMMITTED'];
$n=0;
$check=function(string $name,callable $change,?string $finding=null)use(&$n,$basis,$mr,$entry,$service):void{
    $input=[$basis,'SEL-1',$mr,[],[$entry],true];$change($input);$before=serialize($input);
    $r=$service->assess(...$input);
    $ok=$finding===null ? $r['status']==='CONSISTENT_PROPOSAL' : $r['status']==='UNRESOLVED' && $r['findings']===[$finding] && $r['remaining_milli_by_row']===null && $r['provenance']===[];
    if(!$ok || $r['operationally_authorized']!==false || serialize($input)!==$before)throw new RuntimeException($name.': '.json_encode($r));
    echo 'PASS '.(++$n).' '.$name."\n";
};
$check('exact immutable row provenance; no operational permission',fn(&$x)=>null);
$check('unresolved selection cannot produce capacity',function(&$x){$x[0]['ready']=false;},'BASIS_UNRESOLVED');
$check('new selected version invalidates old basis',function(&$x){$x[1]='SEL-2';},'BASIS_STALE');
$check('unknown commitments not zero',function(&$x){$x[5]=false;},'COMMITMENTS_NOT_RECONCILED');
$check('foreign MR rejected',function(&$x){$x[2]['project']='P2';},'MR_PROJECT_OR_VERSION_UNRESOLVED');
$check('missing MR version',function(&$x){unset($x[2]['version']);},'MR_PROJECT_OR_VERSION_UNRESOLVED');
$check('empty selection not no demand',function(&$x){$x[0]['rows']=[];},'EMPTY_OR_MISSING_MANIFEST');
$check('foreign selected row',function(&$x){$x[0]['rows'][0]['project']='P2';},'INVALID_OR_DUPLICATE_SOURCE_ROW');
$check('missing hierarchy pin',function(&$x){unset($x[0]['rows'][0]['hierarchy']);},'SOURCE_IDENTITY_UNRESOLVED');
$check('duplicate selected source row',function(&$x){$x[0]['rows'][]=$x[0]['rows'][0];},'INVALID_OR_DUPLICATE_SOURCE_ROW');
$check('no fuzzy material matching',function(&$x){$x[2]['lines'][0]['material']='SAME-CODE-OTHER-ID';},'EXACT_IDENTITY_UNIT_SCOPE_MISMATCH');
$check('M and EA cannot substitute',function(&$x){$x[2]['lines'][0]['unit']='EA';},'EXACT_IDENTITY_UNIT_SCOPE_MISMATCH');
$check('unit spelling not converted',function(&$x){$x[2]['lines'][0]['unit']='m';},'EXACT_IDENTITY_UNIT_SCOPE_MISMATCH');
$check('scope mismatch not inferred from topology',function(&$x){$x[2]['lines'][0]['scope']='S2';},'EXACT_IDENTITY_UNIT_SCOPE_MISMATCH');
$check('free text MR remains unexplained here',function(&$x){unset($x[2]['lines'][0]['material']);},'MR_IDENTITY_UNRESOLVED');
$check('float quantities refused',function(&$x){$x[4][0]['quantity_milli']=4.0;},'SOURCE_NOT_SELECTED_OR_QUANTITY_INVALID');
$check('zero quantity refused',function(&$x){$x[4][0]['quantity_milli']=0;},'SOURCE_NOT_SELECTED_OR_QUANTITY_INVALID');
$check('negative quantity refused',function(&$x){$x[4][0]['quantity_milli']=-1;},'SOURCE_NOT_SELECTED_OR_QUANTITY_INVALID');
$check('decimal string not silently parsed',function(&$x){$x[4][0]['quantity_milli']='4000';},'SOURCE_NOT_SELECTED_OR_QUANTITY_INVALID');
$check('numeric18,3 upper bound',function(&$x){$x[0]['rows'][0]['quantity_milli']=1000000000000000000;},'INVALID_OR_DUPLICATE_SOURCE_ROW');
$check('stale entry basis',function(&$x){$x[4][0]['basis_version']='SEL-OLD';},'ENTRY_PROJECT_OR_BASIS_MISMATCH');
$check('foreign entry project',function(&$x){$x[4][0]['project']='P2';},'ENTRY_PROJECT_OR_BASIS_MISMATCH');
$check('removed revision row not reused',function(&$x){$x[4][0]['row_id']='R-OLD';},'SOURCE_NOT_SELECTED_OR_QUANTITY_INVALID');
$check('changed MR version requires fresh explanation',function(&$x){$x[2]['version']='MRV2';},'MR_LINE_OR_VERSION_MISMATCH');
$check('other MR cannot reuse candidate',function(&$x){$x[4][0]['mr_id']='MR2';},'MR_LINE_OR_VERSION_MISMATCH');
$check('duplicate candidate rejected not counted twice',function(&$x){$x[4][]=$x[4][0];},'INVALID_OR_DUPLICATE_ENTRY');
$check('partial explanation not ready',function(&$x){$x[4][0]['quantity_milli']=3999;},'MR_LINE_NOT_FULLY_EXPLAINED');
$check('over-explained MR rejected',function(&$x){$x[4][0]['quantity_milli']=4001;},'MR_LINE_OVERMAPPED');
$check('existing commitments plus candidate exactly fit',function(&$x)use($legacy){$x[3]=[$legacy];});
$check('existing commitments plus candidate overflow',function(&$x)use($legacy){$x[3]=[$legacy];$x[3][0]['quantity_milli']=6001;},'DEMAND_ROW_OVERCOMMITTED');
$check('draft ledger is not silently reserving',function(&$x)use($legacy){$x[3]=[$legacy];$x[3][0]['state']='DRAFT';},'LEDGER_STATE_REQUIRES_RECONCILIATION');
$check('stale historical commitment needs reconciliation',function(&$x)use($legacy){$x[3]=[$legacy];$x[3][0]['basis_version']='OLD';},'ENTRY_PROJECT_OR_BASIS_MISMATCH');
$check('same event cannot appear as history and candidate',function(&$x)use($legacy){$x[3]=[$legacy];$x[3][0]['id']='E1';},'INVALID_OR_DUPLICATE_ENTRY');
$check('duplicate MR lines',function(&$x){$x[2]['lines'][]=$x[2]['lines'][0];},'INVALID_OR_DUPLICATE_MR_LINE');
$check('one milli precision retained',function(&$x){$x[2]['lines'][0]['quantity_milli']=1;$x[4][0]['quantity_milli']=1;});
$check('different rows explain one line without invented allocation authority',function(&$x){$x[0]['rows'][]=$x[0]['rows'][0];$x[0]['rows'][1]['id']='R2';$x[4][0]['quantity_milli']=2000;$x[4][]=$x[4][0];$x[4][1]['id']='E2';$x[4][1]['row_id']='R2';});
$r=$service->assess($basis,'SEL-1',$mr,[],[$entry],true);
if($r['remaining_milli_by_row']!==['R1'=>6000] || $r['provenance'][0]['revision']!=='REV1')throw new RuntimeException('exact output failed');
echo "PASS ".(++$n)." exact remaining and revision output\n";
echo "RESULT $n cases PASS (offline synthetic contract; no database/application tests)\n";
