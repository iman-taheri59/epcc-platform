<?php

declare(strict_types=1);
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionNotificationService;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Private integration-test worker. No HTTP route, no provisioning or new grants.
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = dirname(__DIR__, 2).'/app';
require $_ENV['APP_BASE_PATH'].'/vendor/autoload.php';
$app = require __DIR__.'/../../app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if (getenv('EPCC_NOTIFICATION_TEST') !== 'isolated' || config('database.connections.pgsql.database') !== 'epcc_test' || ! str_starts_with((string) config('database.connections.pgsql.host'), '/tmp/epcc-')) {
        throw new RuntimeException('Refusing non-isolated notification test.');
    }
    $actor = app(TenantContext::class)->forIdentityLookup(fn () => User::findOrFail($argv[1]));
    $project = Project::findOrFail($argv[2]);
    echo "ready\n";
    flush();
    if (($argv[4] ?? '') === 'fk-probe') {
        app(TenantContext::class)->runAs($actor->company_id, fn () => DB::select('SELECT id FROM projects WHERE id=? FOR KEY SHARE', [$project->id]), $project->id);
        echo "key-share-compatible\n";
        exit(0);
    }
    if (($argv[4] ?? '') === 'edit') {
        app(InspectionNotificationService::class)->updateDraft($project, $actor, $argv[3], json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR));
        echo "edited\n";
        exit(0);
    }
    if (($argv[4] ?? '') === 'acknowledge') {
        app(InspectionNotificationService::class)->acknowledge($project, $actor, $argv[3], json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR));
        echo "acknowledged\n";
        exit(0);
    }
    if (($argv[4] ?? '') === 'prerequisite') {
        $payload = json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR);
        app(InspectionNotificationService::class)->updatePrerequisite($project, $actor, $argv[3], $payload['id'], $payload['command']);
        echo "prerequisite-updated\n";
        exit(0);
    }
    if (($argv[4] ?? '') === 'cancel') {
        app(InspectionNotificationService::class)->cancel($project, $actor, $argv[3], json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR));
        echo "cancelled\n";
        exit(0);
    }
    app(InspectionNotificationService::class)->issue($project, $actor, $argv[3], ['version' => 1]);
    echo "issued\n";
    exit(0);
} catch (ValidationException $e) {
    echo "refused\n";
    exit(2);
} catch (HttpExceptionInterface $e) {
    if (in_array($e->getStatusCode(), [403, 404], true)) {
        echo "refused\n";
        exit(2);
    }
    fwrite(STDERR, $e->getMessage()."\n");
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(3);
}
