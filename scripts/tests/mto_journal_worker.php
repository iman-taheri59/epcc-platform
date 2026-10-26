<?php

declare(strict_types=1);

// Explicit synthetic test harness. Never invoke against customer databases.
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = dirname(__DIR__, 2).'/app';
require $_ENV['APP_BASE_PATH'].'/vendor/autoload.php';
$app = require $_ENV['APP_BASE_PATH'].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('EPCC_APP_JOURNAL_TEST') !== 'isolated' || config('database.connections.pgsql.database') !== 'epcc_test'
    || !str_starts_with((string) config('database.connections.pgsql.host'), '/tmp/epcc-app-journal-sock-')) {
    throw new RuntimeException('Refusing non-isolated application journal test.');
}
$tenant = app(App\Support\TenantContext::class);
$project = $tenant->forIdentityLookup(fn () => App\Models\Project::where('code', 'PH-14')->firstOrFail());
$pars = $tenant->forIdentityLookup(fn () => App\Models\Company::where('code', 'EPC-PARS')->firstOrFail());
$user = $tenant->forIdentityLookup(fn () => App\Models\User::where('email', 'rezaei@pars.example')->firstOrFail());
$owner = $tenant->forIdentityLookup(fn () => App\Models\User::where('company_id', $project->owner_company_id)->firstOrFail());
$emit = static function (array $value): void { echo json_encode($value, JSON_THROW_ON_ERROR)."\n"; flush(); };
if (($argv[1] ?? '') === 'prepare') {
    $result = $tenant->runAs($pars->id, function () use ($project, $user): array {
        $staging = app(App\Services\MtoStagingService::class);
        $doc = $staging->createDocument($project, $user, 'JOURNAL-RACE-'.Illuminate\Support\Str::uuid(), 'Synthetic race', 'آزمون رقابت', lang: 'en');
        $ids = [];
        foreach (['A' => 100, 'B' => 110] as $code => $qty) {
            $r = $staging->createRevision($doc, $user, $code, [['mto_item_no' => '10', 'commodity_code' => 'PIP-CS-6-SCH40',
                'raw_description' => 'Synthetic pipe', 'raw_quantity' => $qty, 'raw_unit' => 'M']], lang: 'en');
            $ids[] = $r->id;
        }
        return $ids;
    }, $project->id);
    $emit(['project' => $project->id, 'company' => $pars->id, 'revisions' => $result]);
    exit;
}
if (($argv[1] ?? '') === 'reopen') {
    $tenant->runAs($owner->company_id, fn () => app(App\Services\ProjectArchiveService::class)->reopen($project, $owner), $project->id);
    $emit(['status' => 'reopened']);
    exit;
}
$mode = $argv[1] ?? 'accept';
$revisionId = $argv[2] ?? '';
$emit(['event' => 'connected', 'pid' => Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]);
if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('GO required'); }
try {
    $tenant->runAs($mode === 'archive' ? $owner->company_id : $pars->id, function () use ($mode, $revisionId, $project, $owner, $emit): void {
        if ($mode === 'archive') {
            app(App\Services\ProjectArchiveService::class)->archive($project, $owner, 'Actual app race harness');
            $emit(['event' => 'operation_done', 'operation' => 'archive']);
        } else {
            $r = App\Models\MtoRevision::findOrFail($revisionId);
            $batch = app(App\Services\MtoAcceptanceService::class)->accept($r, 'en');
            $j = $batch->acceptanceJournal()->firstOrFail();
            $emit(['event' => 'operation_done', 'operation' => 'accept', 'batch' => $batch->id, 'ordinal' => $j->ordinal]);
        }
        $command = trim((string) fgets(STDIN));
        if ($command === 'ROLLBACK') { throw new RuntimeException('deliberate worker rollback'); }
        if ($command !== 'COMMIT') { throw new RuntimeException('COMMIT or ROLLBACK required'); }
    }, $project->id);
    $emit(['event' => 'committed']);
} catch (Illuminate\Validation\ValidationException $e) {
    $emit(['event' => 'rejected', 'fields' => array_keys($e->errors())]);
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'deliberate worker rollback') { throw $e; }
    $emit(['event' => 'rolled_back']);
}
