<?php

declare(strict_types=1);
require dirname(__DIR__,2).'/app/app/Services/Engineering/MaterialScreening.php';
use App\Services\Engineering\MaterialScreening;
$ref=dirname(__DIR__,2).'/standards-packs/reference';$engine=new MaterialScreening($ref);$checks=0;
function verify(bool $condition,string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function codes(array $result): array { return array_column($result['findings'],'code'); }
function has(array $result,string $code): bool { return in_array($code,codes($result),true); }
$base=['component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in','reference_standard'=>'ASME B36.10M-2022',
 'material_spec'=>'SYNTHETIC QA ONLY','declaration_reason'=>'Test fixture only','spec_number'=>'QA','spec_revision'=>'A','spec_clause'=>'1',
 'source_text'=>'Original <script>wording</script> unchanged.', 'properties'=>['product_form'=>'SEAMLESS','schedule'=>'40','outside_diameter_mm'=>'168.3','wall_thickness_mm'=>'7.11']];
$run=fn (array $delta=[])=>$engine->check(array_replace($base,$delta));
$r=$run();verify($r['status']==='REVIEW_REQUIRED','Matching loaded reference is not approval');
verify(!has($r,'REFERENCE_VALUE_DIFFERENCE'),'Matching nominal values');
verify(count($r['references'])===2,'Both pack versions and hashes retained');
verify(!$r['engineering_approved'] && !$r['automatic_consumption_allowed'],'No automatic approval or consumption');
$before=serialize($base);$engine->check($base);verify(serialize($base)===$before,'Source never mutated');
verify($r['input_sha256']===$run()['input_sha256'],'Deterministic input fingerprint');
verify($r['input_sha256']!==$run(['spec_revision'=>'B'])['input_sha256'],'Source revision affects fingerprint');
foreach (['PIPE','FLANGE','VALVE','GASKET','BOLTING'] as $family) {
 $r=$run(['component_type'=>$family,'properties'=>[]]);verify($r['status']==='INCOMPLETE','Missing context '.$family);
 verify(has($r,'SOURCE_REVIEW_REQUIRED'),'Source verification always needed '.$family);
}
foreach (['spec_number','spec_revision','spec_clause','source_text','reference_standard','material_spec'] as $key) {
 verify(has($run([$key=>'  ']),'SOURCE_FIELD_REQUIRED'),'Blank source field '.$key);
}
foreach (['wall_thickness_mm'=>84.15,'outside_diameter_mm'=>14.22] as $key=>$value) {
 verify(has($run(['properties'=>array_replace($base['properties'],[$key=>$value])]),'PIPE_BORE_CLOSED'),'Closed bore including equality');
}
foreach ([0,-1,'1e3','10 mm','1.12345',true,[],INF] as $value) {
 // INF cannot be represented in JSON and must not be treated as a legitimate declaration.
 if ($value===INF) { try {$run(['properties'=>['wall_thickness_mm'=>INF]]);verify(false,'Non-JSON input accepted');}catch(JsonException){verify(true,'Non-JSON number refused');}continue; }
 verify(has($run(['properties'=>['wall_thickness_mm'=>$value]]),'DIMENSION_INVALID'),'Bad dimension rejected');
}
verify(has($run(['properties'=>'bad']),'PROPERTY_OBJECT_REQUIRED'),'Reject scalar properties');
verify(has($run(['properties'=>[['wall_thickness_mm'=>10]]]),'PROPERTY_OBJECT_REQUIRED'),'Reject list properties');
verify(has($run(['properties'=>['trim'=>'X']]),'PROPERTY_NOT_SUPPORTED'),'Wrong family attribute');
verify(has($run(['reference_standard'=>'ASME B16.9-2018']),'FITTING_STANDARD_MISAPPLIED'),'No B16.9 on pipe');
verify(has($run(['component_type'=>'CAP']),'FAMILY_NOT_SUPPORTED'),'Do not replace existing fitting workflow');
verify(has($run(['reference_standard'=>'ASME B36.10M-2018']),'REFERENCE_EDITION_NOT_MATCHED'),'Edition must match exactly');
verify(has($run(['size_designation'=>'NPS 52 in']),'REFERENCE_SIZE_NOT_RESOLVED'),'No borrowed 48 inch upper bound');
verify(has($run(['size_designation'=>'DN 150']),'REFERENCE_SIZE_NOT_RESOLVED'),'No DN/NPS guessing');
verify(has($run(['properties'=>array_replace($base['properties'],['schedule'=>'40S'])]),'SCHEDULE_STANDARD_UNRESOLVED'),'No carbon stainless schedule assumption');
verify(has($run(['reference_standard'=>'ASME B36.19M-2018','properties'=>array_replace($base['properties'],['schedule'=>'XS'])]),'SCHEDULE_STANDARD_UNRESOLVED'),'No stainless alias inference');
verify(has($run(['properties'=>array_replace($base['properties'],['schedule'=>'999'])]),'REFERENCE_PAIR_NOT_LOADED'),'Missing pair is not outside-standard');
$r=$run(['properties'=>array_replace($base['properties'],['wall_thickness_mm'=>'7.12'])]);
verify(has($r,'REFERENCE_VALUE_DIFFERENCE') && $r['status']==='REVIEW_REQUIRED','Nominal difference is not manufacturing rejection');
$r=$run(['size_designation'=>'NPS 12 in','properties'=>['product_form'=>'SEAMLESS','schedule'=>'STD','wall_thickness_mm'=>'10.31']]);
verify(has($r,'REFERENCE_VALUE_DIFFERENCE'),'STD not aliased to schedule 40 above NPS10');
verify(has($run(['component_type'=>'FLANGE','reference_standard'=>'ASME B16.47-2025','properties'=>['pressure_rating'=>'Class 150','facing'=>'RF']]),'FLANGE_SERIES_REQUIRED'),'Series not defaulted');
verify(!has($run(['component_type'=>'FLANGE','reference_standard'=>'ASME B16.47-2025','properties'=>['pressure_rating'=>'Class 150','facing'=>'RF','series'=>'B']]),'FLANGE_SERIES_REQUIRED'),'Explicit series retained');
verify(has($run(['component_type'=>'FLANGE','properties'=>['bore_mm'=>100,'outside_diameter_mm'=>100]]),'IMPOSSIBLE_BORE'),'Flange geometry');
verify(has($run(['component_type'=>'GASKET','properties'=>['inside_diameter_mm'=>110,'outside_diameter_mm'=>100]]),'IMPOSSIBLE_BORE'),'Gasket geometry');
foreach (['Class 150','PN 16','150 psi'] as $rating) {verify(!has($run(['component_type'=>'FLANGE','properties'=>['pressure_rating'=>$rating]]),'RATING_BASIS_UNRESOLVED'),'Explicit rating basis');}
verify(has($run(['component_type'=>'FLANGE','properties'=>['pressure_rating'=>'150']]),'RATING_BASIS_UNRESOLVED'),'Unqualified rating');
verify(has($run(['component_type'=>'BOLTING','qualifier'=>'STUD_BOLT','size_designation'=>'M24 × 3 mm','properties'=>['thread_standard'=>'QA']]),'BOLT_LENGTH_REQUIRED'),'Stud length');
verify(!has($run(['component_type'=>'BOLTING','qualifier'=>'NUT','size_designation'=>'M24 × 3 mm','properties'=>['thread_standard'=>'QA']]),'BOLT_LENGTH_REQUIRED'),'Nut is not a bolt');
verify(has($run(['component_type'=>'BOLTING','qualifier'=>'BOLT']),'THREAD_DESIGNATION_REQUIRED'),'Do not use pipe NPS as thread');
verify(has($run(['component_type'=>'BOLTING','qualifier'=>'bolt assembly QA']),'BOLTING_SUBTYPE_REVIEW'),'Do not infer subtype from unrecognized wording');
verify(has((new MaterialScreening('/path-not-present'))->check($base),'REFERENCE_UNAVAILABLE'),'Missing reference fails closed');
$tmp=sys_get_temp_dir().'/epcc-material-check-'.bin2hex(random_bytes(5));mkdir($tmp);
try {file_put_contents($tmp.'/pipe-sizes.ref.json','{}');verify(has((new MaterialScreening($tmp))->check($base),'REFERENCE_UNAVAILABLE'),'Corrupt reference fails closed');}finally{unlink($tmp.'/pipe-sizes.ref.json');rmdir($tmp);}
echo "PASS: $checks assertions; read-only screening, no database or source changes.\n";
