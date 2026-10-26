<?php

declare(strict_types=1);

require __DIR__.'/../domain/DemandBasisReconciliation.php';
use Epcc\Prototype\DemandBasisReconciliation;

$s = new DemandBasisReconciliation;
$m = ['project'=>'P1','selection_version'=>'SEL1','rows'=>[
    ['id'=>'R1','batch'=>'B1','revision'=>'REV1','hierarchy'=>'H1','scope'=>'S1','material'=>'MAT1','unit'=>'M','quantity_milli'=>10000],
]];
$v=$s->version($m);
$c=['id'=>'C1','project'=>'P1','basis_version'=>$v,'row_id'=>'R1','quantity_milli'=>4000];
$n=0;
$check=function($name,$assert)use(&$n){if(!$assert())throw new RuntimeException($name);echo 'PASS '.(++$n).' '.$name."\n";};
$check('repeatable domain separated content version',fn()=>str_starts_with($v,'demand-basis-v1:')&&$v===$s->version($m));
$reordered=array_reverse($m,true);$reordered['rows'][0]=array_reverse($m['rows'][0],true);
$check('object key order irrelevant',fn()=>$s->version($reordered)===$v);
$multi=$m;$multi['rows'][]=$m['rows'][0];$multi['rows'][1]['id']='R2';$reverse=$multi;$reverse['rows']=array_reverse($multi['rows']);
$check('row input ordering irrelevant',fn()=>$s->version($multi)===$s->version($reverse));
foreach(['project','selection_version']as$key){$x=$m;$x[$key].='2';$check('version binds '.$key,fn()=>$s->version($x)!==$v);}
foreach(['id','batch','revision','hierarchy','scope','material','unit']as$key){$x=$m;$x['rows'][0][$key].='2';$check('version binds row '.$key,fn()=>$s->version($x)!==$v);}
$x=$m;$x['rows'][0]['quantity_milli']++;
$check('version binds exact milli quantity',fn()=>$s->version($x)!==$v);
foreach(['empty','float','extra','duplicate','missing','bounds']as$kind){$x=$m;if($kind==='empty')$x['rows']=[];if($kind==='float')$x['rows'][0]['quantity_milli']=1.0;if($kind==='extra')$x['untrusted']='ignored?';if($kind==='duplicate')$x['rows'][]=$x['rows'][0];if($kind==='missing')unset($x['rows'][0]['hierarchy']);if($kind==='bounds')$x['rows'][0]['quantity_milli']=1000000000000000000;
$check('invalid manifest '.$kind.' refused',function()use($s,$x){try{$s->version($x);return false;}catch(InvalidArgumentException){return true;}});}
$review=function($name,$old,$new,$entries,$code=null,$disposition=null,$complete=true,$expected=null)use($s,$check){$before=serialize([$old,$new,$entries]);$r=$s->review($old,$new,$expected??$s->version($new),$entries,$complete);
$check($name,fn()=>$r['operationally_authorized']===false&&$r['remaining_milli']===null&&$r['transfers']===[]&&$before===serialize([$old,$new,$entries])&&($code===null||in_array($code,$r['findings'],true))&&($disposition===null||($r['entries'][0]['disposition']??null)===$disposition));};
$review('current reference not available capacity',$m,$m,[$c],null,'CURRENT_REFERENCE_ONLY');
$x=$m;$x['selection_version']='SEL2';$review('unchanged row in new selection still needs review',$m,$x,[$c],null,'UNCHANGED_ROW_NEW_BASIS_REVIEW');
$x=$m;$x['rows'][0]['id']='R2';$x['rows'][0]['revision']='REV2';$review('replacement row never auto receives old commitment',$m,$x,[$c],null,'SOURCE_NOT_IN_CURRENT_BASIS');
$x=$m;$x['rows'][0]['quantity_milli']=3000;$review('same immutable ID quantity mutation',$m,$x,[$c],'IMMUTABLE_SOURCE_IDENTITY_MUTATED');
$x=$m;$x['rows'][0]['unit']='EA';$review('same immutable ID unit mutation',$m,$x,[$c],'IMMUTABLE_SOURCE_IDENTITY_MUTATED');
$x=$m;$x['project']='P2';$review('foreign target project',$m,$x,[$c],'PROJECT_MISMATCH');
$review('concurrent current version drift',$m,$m,[$c],'CURRENT_VERSION_CHANGED',null,true,'STALE');
$x=$c;$x['basis_version']='OLD';$review('unknown historical version',$m,$m,[$x],'INVALID_OR_UNRECONCILED_HISTORY');
$x=$c;$x['project']='P2';$review('foreign historical commitment',$m,$m,[$x],'INVALID_OR_UNRECONCILED_HISTORY');
$review('duplicate history not counted twice',$m,$m,[$c,$c],'INVALID_OR_UNRECONCILED_HISTORY');
$x=$c;$x['quantity_milli']=10001;$review('old overcommitment not hidden by new revision',$m,$m,[$x],'HISTORICAL_OVERCOMMITMENT');
$x=$c;$x['id']='C2';$x['quantity_milli']=7000;$review('aggregate old overcommitment',$m,$m,[$c,$x],'HISTORICAL_OVERCOMMITMENT');
$review('hidden or partial inventory not complete',$m,$m,[$c],'INVENTORY_VISIBILITY_OR_RECONCILIATION_INCOMPLETE',null,false);
$review('empty input never no commitments',$m,$m,[],'EMPTY_INPUT_NOT_PROOF_OF_NO_COMMITMENTS');
$review('all historical references retained',$m,$multi,[$c],null,'UNCHANGED_ROW_NEW_BASIS_REVIEW');
$x=$m;$x['rows']=[];$review('empty new manifest not retirement',$m,$x,[$c],'INVALID_MANIFEST',null,true,'ANY');
echo "RESULT $n offline cases PASS; no database or operational transfer\n";
