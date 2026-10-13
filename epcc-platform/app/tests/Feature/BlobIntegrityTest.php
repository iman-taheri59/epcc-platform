<?php

namespace Tests\Feature;

use App\Models\{Company, DocumentFile, Project};
use App\Services\DocumentFileStore;
use App\Support\TenantContext;
use Tests\TestCase;

class BlobIntegrityTest extends TestCase
{
    public function test_every_stored_blob_is_present_and_matches_its_hash(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);
        $this->seed(\Database\Seeders\AuthSeeder::class);

        $t = app(TenantContext::class);
        $p = Project::where('code', 'PH-14')->first();
        $c = Company::where('code', 'EPC-PARS')->first();

        $store = app(DocumentFileStore::class);

        [$ok, $bad] = $t->runAs($c->id, function () use ($store) {
            $ok = 0; $bad = [];
            foreach (DocumentFile::all() as $f) {
                $store->verify($f) ? $ok++ : $bad[] = $f->stored_path;
            }
            return [$ok, $bad];
        }, $p->id);

        fwrite(STDERR, "\n>>> blobs verified ok={$ok} broken=".count($bad)."\n");
        $this->assertSame([], $bad, 'a stored blob is missing or corrupt');
        $this->assertGreaterThan(0, $ok, 'no blobs were checked at all');
    }
}
