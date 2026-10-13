<?php
// Non-destructive preflight. Documentation/test artifacts are not proof of deployed source.
$root=dirname(__DIR__);$missing=[];
foreach ([
 'app/app/Services/ProjectMaterialSchema.php',
 'app/app/Services/PmsEntryService.php','app/app/Http/Controllers/PmsController.php',
 'app/database/migrations/2026_09_29_235000_add_pms_entry_provenance.php',
 'app/resources/views/piping/pms.blade.php','app/resources/views/piping/partials/pms-finding.blade.php',
 'app/lang/fa/pms.php','app/lang/en/pms.php','app/lang/fa/pms_findings.php',
 'app/tests/Feature/PmsEntryTest.php','app/tests/Feature/PmsEntryMigrationTest.php',
 'app/app/Services/Engineering/MaterialFindingDispositions.php',
 'app/database/migrations/2026_09_29_230000_add_material_finding_dispositions.php',
 'app/resources/views/piping/partials/material-finding-dispositions.blade.php',
 'app/lang/fa/finding_dispositions.php','app/lang/en/finding_dispositions.php',
 'app/tests/Feature/MaterialFindingDispositionTest.php','app/tests/Feature/MaterialFindingDispositionMigrationTest.php',
 'app/app/Services/Engineering/MaterialFindingResponses.php',
 'app/database/migrations/2026_09_29_210000_add_material_finding_responses.php',
 'app/resources/views/piping/partials/material-finding-responses.blade.php',
 'app/lang/fa/finding_responses.php','app/lang/en/finding_responses.php',
 'app/tests/Feature/MaterialFindingResponseTest.php','app/tests/Feature/MaterialFindingResponseMigrationTest.php',
 'app/app/Services/Engineering/MaterialScreeningEvidence.php',
 'app/database/migrations/2026_09_29_180000_capture_material_screening_snapshots.php',
 'app/resources/views/piping/partials/material-screening-findings.blade.php',
 'app/tests/Feature/MaterialScreeningSnapshotTest.php','app/tests/Feature/MaterialScreeningSnapshotMigrationTest.php',
 'app/app/Services/Engineering/MaterialScreening.php',
 'app/resources/views/piping/partials/material-screening.blade.php',
 'app/lang/fa/material_screening.php','app/lang/en/material_screening.php',
 'app/database/migrations/2026_09_29_150000_extend_project_material_declarations.php',
 'app/resources/views/piping/partials/project-material-fields.blade.php',
 'app/lang/fa/project_materials.php','app/lang/en/project_materials.php',
 'app/tests/Feature/MaterialReviewTest.php','app/tests/Feature/MaterialMigrationTest.php',
] as $path) { if (!is_file($root.'/'.$path)) {$missing[]=$path;} }
foreach ([
 'app/app/Services/FittingReviewService.php'=>'material_family',
 'app/app/Services/ProjectFittingDefinitionService.php'=>'ProjectMaterialSchema',
 'app/app/Http/Controllers/ProjectFittingDefinitionController.php'=>'ProjectMaterialSchema',
] as $path=>$marker) {
 if (!is_file($root.'/'.$path) || !str_contains(file_get_contents($root.'/'.$path),$marker)) {$missing[]=$path.' [missing '.$marker.']';}
}
echo json_encode(['status'=>$missing?'INCOMPLETE_BASELINE':'SOURCE_FILES_PRESENT_NOT_RUNTIME_VERIFIED','missing'=>$missing],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($missing?1:0);
