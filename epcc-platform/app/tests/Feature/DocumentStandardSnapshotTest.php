<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Itp;
use App\Models\ItpStage;
use App\Models\Project;
use App\Models\ProjectStandard;
use App\Models\Standard;
use App\Models\StandardCitation;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\ItpService;
use App\Services\StandardsLibrary;
use App\Support\CdeState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DocumentStandardSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function actor(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function scoped(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->actor()->company_id, $fn, $this->project()->id);
    }

    /** @return array{Document, ItpStage, StandardCitation, Standard} */
    private function fixture(?string $edition = '2020'): array
    {
        $itp = app(ItpService::class)->createBlank($this->project(), $this->actor(), 'PIPING', 'SNAP-1', 'Reference test', 'آزمون ارجاع');
        $stage = app(ItpService::class)->addStage($itp, $this->actor(), ['clause' => '1.1', 'title_en' => 'Inspect', 'title_fa' => 'بازرسی']);
        $standard = app(StandardsLibrary::class)->define('TEST', '99', 'Test standard', edition: '2099');
        $citation = app(StandardsLibrary::class)->cite($this->project(), $standard, StandardCitation::ITP_STAGE, $stage->id, '§5');
        $citation->update(['source_edition' => $edition]);
        $document = app(ItpService::class)->registerAsDocument($itp->fresh(), $this->actor());
        app(DocumentRegister::class)->transition($document, $this->actor(), CdeState::SHARED, 'S1');

        return [$document->fresh(), $stage->fresh(), $citation->fresh(), $standard];
    }

    private function publish(Document $document, string $suitability = 'A1'): DocumentRevision
    {
        return app(DocumentRegister::class)->transition($document, $this->actor(), CdeState::PUBLISHED, $suitability);
    }

    private function sqlRefused(callable $fn, string $message = 'DR01_'): void
    {
        try {
            DB::transaction($fn);
            $this->fail('Expected the database backstop to refuse the write.');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_published_identity_edition_and_clause_survive_catalogue_and_adoption_changes(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage, $citation, $standard] = $this->fixture();
            $revision = $this->publish($doc, 'B1');
            $before = $revision->standard_reference_snapshot;
            $standard->update(['body' => 'CHANGED', 'designation' => '100', 'edition' => '2030', 'title_en' => 'New title', 'is_active' => false]);
            ProjectStandard::where('standard_id', $standard->id)->update(['edition' => '2031']);

            $this->assertSame('FROZEN', $revision->standardBasisStatus());
            $this->assertSame('2020', $revision->frozenStandardStage($stage->id)['citations'][0]['edition']);
            $this->assertSame('TEST 99-2020 §5', $stage->fresh()->printedStandards());
            $this->assertSame('TEST 99-2020 §5', $citation->fresh()->printed());
            $this->assertSame($before, $revision->fresh()->standard_reference_snapshot);
        });
    }

    public function test_explicit_project_edition_is_allowed_but_catalogue_fallback_is_not(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage, , $standard] = $this->fixture(null);
            try {
                $this->publish($doc);
                $this->fail('Missing explicit edition must block publication.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('standard_references', $e->errors());
            }
            $this->assertSame(CdeState::SHARED, $doc->fresh()->currentRevision->cde_state);
            $this->assertNull($doc->fresh()->currentRevision->standard_reference_snapshot);
            ProjectStandard::where('standard_id', $standard->id)->update(['edition' => '2018']);
            $snapshot = $this->publish($doc)->frozenStandardStage($stage->id);
            $this->assertSame('2018', $snapshot['citations'][0]['edition']);
            $this->assertSame('PROJECT_EDITION', $snapshot['citations'][0]['edition_source']);
        });
    }

    public function test_unresolved_standard_text_remains_in_draft_and_refuses_publication_atomically(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage] = $this->fixture();
            $stage->update(['reference' => 'UNKNOWN STANDARD']);
            $this->sqlRefused(fn () => DB::table('document_revisions')->where('id', $doc->current_revision_id)->update(['cde_state' => 'PUBLISHED']), 'DR01_UNRESOLVED_REFERENCE');
            $this->assertSame('UNKNOWN STANDARD', $stage->fresh()->reference);
            $this->assertSame('SHARED', $doc->fresh()->currentRevision->cde_state);
            $this->assertNull($doc->fresh()->currentRevision->standard_reference_snapshot);
        });
    }

    public function test_sql_cannot_mutate_reopen_delete_or_detach_finalized_history(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage, $citation] = $this->fixture();
            $rev = $this->publish($doc);
            $before = $rev->standard_reference_snapshot;
            $this->sqlRefused(fn () => DB::table('document_revisions')->where('id', $rev->id)->update(['standard_reference_snapshot' => null]));
            $this->sqlRefused(fn () => DB::table('document_revisions')->where('id', $rev->id)->update(['cde_state' => 'WIP']));
            $this->sqlRefused(fn () => DB::table('document_revisions')->where('id', $rev->id)->delete());
            $this->sqlRefused(fn () => DB::table('standard_citations')->where('id', $citation->id)->update(['source_edition' => '2024']));
            $this->sqlRefused(fn () => DB::table('standard_citations')->where('id', $citation->id)->delete());
            $this->sqlRefused(fn () => DB::table('itp_stages')->where('id', $stage->id)->update(['reference' => 'Other']));
            $this->sqlRefused(fn () => DB::table('itps')->where('id', $stage->itp_id)->update(['document_id' => null]));
            $this->assertSame($before, $rev->fresh()->standard_reference_snapshot);
            $this->assertSame('2020', $citation->fresh()->source_edition);
        });
    }

    public function test_new_revision_can_select_a_new_basis_without_changing_old_revision(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage, $citation] = $this->fixture();
            $old = $this->publish($doc);
            $before = $old->standard_reference_snapshot;
            $next = app(DocumentRegister::class)->issueRevision($doc, $this->actor(), 'B');
            $this->assertSame('DRAFT', $next->standardBasisStatus());
            $citation->update(['source_edition' => '2022', 'clause' => '§7']);
            app(DocumentRegister::class)->transition($doc, $this->actor(), CdeState::SHARED, 'S1');
            $new = $this->publish($doc);
            $this->assertSame('TEST 99-2020 §5', $stage->fresh()->printedStandards([], $old->fresh()));
            $this->assertSame('TEST 99-2022 §7', $stage->fresh()->printedStandards([], $new));
            $this->assertSame('ARCHIVED', $old->fresh()->cde_state);
            $this->assertSame($before, $old->fresh()->standard_reference_snapshot);
        });
    }

    public function test_empty_scope_has_a_real_empty_snapshot_and_draft_cannot_forge_one(): void
    {
        $this->scoped(function (): void {
            $doc = app(DocumentRegister::class)->register($this->project(), $this->actor(), ['discipline' => 'MECH', 'doc_type' => 'DWG', 'title_en' => 'Drawing', 'title_fa' => 'نقشه']);
            $this->sqlRefused(fn () => DB::table('document_revisions')->where('id', $doc->current_revision_id)->update(['standard_reference_snapshot' => '{}']));
            app(DocumentRegister::class)->transition($doc, $this->actor(), 'SHARED', 'S1');
            $rev = $this->publish($doc);
            $this->assertSame([], $rev->standard_reference_snapshot['stages']);
            $this->assertSame('FROZEN', $rev->standardBasisStatus());
        });
    }

    public function test_sql_rejects_orphan_and_cross_project_citation_hosts(): void
    {
        $this->scoped(function (): void {
            [, , $citation] = $this->fixture();
            $this->sqlRefused(fn () => $citation->update(['citable_id' => (string) Str::uuid()]));
            $other = Project::where('code', 'BB-2')->firstOrFail();
            $this->sqlRefused(fn () => $citation->fresh()->update(['project_id' => $other->id]), '');
            $this->assertSame($this->project()->id, $citation->fresh()->project_id);
        });
    }

    public function test_source_edition_http_edit_is_not_adoption_and_batch_is_atomic(): void
    {
        [$doc, , $citation] = $this->scoped(fn () => $this->fixture(null));
        $url = "/projects/PH-14/documents/{$doc->id}/standard-editions?lang=en";
        $before = $this->scoped(fn () => ProjectStandard::where('standard_id', $citation->standard_id)->first()->toArray());
        $this->actingAs($this->actor())->post($url, ['revision_id' => $doc->current_revision_id, 'editions' => [$citation->id => '2016']])->assertRedirect();
        $this->assertSame('2016', $this->scoped(fn () => $citation->fresh()->source_edition));
        $this->assertSame($before, $this->scoped(fn () => ProjectStandard::where('standard_id', $citation->standard_id)->first()->toArray()));
        $this->post($url, ['revision_id' => $doc->current_revision_id, 'editions' => [$citation->id => '2024', 'bad-id' => '2025']])->assertSessionHasErrors('editions');
        $this->assertSame('2016', $this->scoped(fn () => $citation->fresh()->source_edition));
        $this->scoped(fn () => $this->publish($doc));
        $this->post($url, ['revision_id' => $doc->current_revision_id, 'editions' => [$citation->id => '2024']])->assertSessionHasErrors('editions');
        $this->assertSame('2016', $this->scoped(fn () => $citation->fresh()->source_edition));
    }

    public function test_history_views_are_localized_escaped_and_keep_the_old_revision_basis(): void
    {
        [$doc, $stage, $citation] = $this->scoped(fn () => $this->fixture());
        $old = $this->scoped(function () use ($doc, $citation): DocumentRevision {
            $citation->update(['clause' => '<script>bad()</script>']);
            $old = $this->publish($doc);
            app(DocumentRegister::class)->issueRevision($doc, $this->actor(), 'B');
            $citation->update(['source_edition' => '2022', 'clause' => '§9']);

            return $old;
        });
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $this->actingAs($this->actor())->get("/projects/PH-14/documents/{$doc->id}/standard-references?revision={$old->id}&lang={$lang}")
                ->assertOk()->assertSee('dir="'.$dir.'"', false)->assertSee('TEST 99-2020')
                ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>bad()</script>', false)->assertDontSee('name="editions[', false);
        }
        $this->get("/projects/PH-14/documents/{$doc->id}/standard-references?lang=en")->assertSee('2022')->assertSee('Source edition');
        $this->get("/projects/PH-14/itp/{$stage->itp_id}?lang=en")->assertOk()->assertSee('TEST 99-2022');
    }

    public function test_other_company_cannot_edit_owned_reference_editions(): void
    {
        [$doc, , $citation] = $this->scoped(fn () => $this->fixture());
        $other = $this->userByEmail('karimi@nioc.example');
        $this->actingAs($other)->post("/projects/PH-14/documents/{$doc->id}/standard-editions", ['revision_id' => $doc->current_revision_id, 'editions' => [$citation->id => '2025']])->assertForbidden();
        $this->assertSame('2020', $this->scoped(fn () => $citation->fresh()->source_edition));
    }

    public function test_legacy_history_is_not_backfilled_or_rendered_from_live_catalogue(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage, $citation] = $this->fixture();
            // Simulate the persisted pre-migration row, not a supported application operation.
            DB::statement('ALTER TABLE document_revisions DISABLE TRIGGER dr01_revision_snapshot');
            try {
                DB::table('document_revisions')->where('id', $doc->current_revision_id)->update(['cde_state' => 'PUBLISHED', 'suitability' => 'A1']);
            } finally {
                DB::statement('ALTER TABLE document_revisions ENABLE TRIGGER dr01_revision_snapshot');
            }
            $revision = $doc->fresh()->currentRevision;
            $this->assertSame('LEGACY_UNCAPTURED', $revision->standardBasisStatus());
            $this->assertSame(__('epcc.standards.history_uncaptured'), $stage->fresh()->printedStandards());
            $this->assertSame(__('epcc.standards.history_uncaptured'), $citation->fresh()->printed());
            app(DocumentRegister::class)->issueRevision($doc, $this->actor(), 'B');
            $this->assertNull($revision->fresh()->standard_reference_snapshot);
        });
    }

    public function test_publication_lock_serializes_citation_edits_on_a_second_connection(): void
    {
        [$doc, , $citation] = $this->scoped(fn () => $this->fixture());
        $config = config('database.connections.pgsql');
        $pdo = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET lock_timeout = '150ms'");
        $stmt = $pdo->prepare("SELECT set_config('epcc.company_id', ?, false), set_config('epcc.project_id', ?, false)");
        $stmt->execute([$this->actor()->company_id, $this->project()->id]);
        $this->scoped(function () use ($doc, $citation, $pdo): void {
            $this->publish($doc); // outer tenant transaction retains publication's row lock
            try {
                $q = $pdo->prepare('UPDATE standard_citations SET source_edition = ? WHERE id = ?');
                $q->execute(['2024', $citation->id]);
                $this->fail('Concurrent edit must contend on publication lock.');
            } catch (\PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
            }
        });
        try {
            $q = $pdo->prepare('UPDATE standard_citations SET source_edition = ? WHERE id = ?');
            $q->execute(['2024', $citation->id]);
            $this->fail('Edit after publication must be refused.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('DR01_REFERENCES_LOCKED', $e->getMessage());
        }
        $this->assertSame('2020', $this->scoped(fn () => $citation->fresh()->source_edition));
    }

    public function test_draft_itp_deletion_cleans_its_references_without_weakening_published_lock(): void
    {
        $this->scoped(function (): void {
            [$doc, $stage, $citation] = $this->fixture();
            Itp::whereKey($stage->itp_id)->delete();
            $this->assertNull(ItpStage::find($stage->id));
            $this->assertNull(StandardCitation::find($citation->id));
            $this->assertSame('SHARED', $doc->fresh()->currentRevision->cde_state);
        });
    }

    public function test_real_forward_migration_keeps_existing_finalized_history_uncaptured(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => ! str_contains($p, '235500_freeze_document')));
        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $doc = $this->scoped(function (): Document {
            $itp = app(ItpService::class)->createBlank($this->project(), $this->actor(), 'PIPING', 'LEGACY-1', 'Legacy', 'قدیمی');
            $stage = app(ItpService::class)->addStage($itp, $this->actor(), ['clause' => '1', 'reference' => 'UNRESOLVED OLD SOURCE']);
            $doc = app(ItpService::class)->registerAsDocument($itp, $this->actor());
            app(DocumentRegister::class)->transition($doc, $this->actor(), 'SHARED', 'S1');
            $this->publish($doc);

            return $doc;
        });
        Artisan::call('migrate', ['--force' => true]);
        $this->scoped(function () use ($doc): void {
            $revision = $doc->fresh()->currentRevision;
            $this->assertSame('PUBLISHED', $revision->cde_state);
            $this->assertSame('LEGACY_UNCAPTURED', $revision->standardBasisStatus());
            $this->assertNull($revision->standard_reference_snapshot);
            $this->assertSame('UNRESOLVED OLD SOURCE', ItpStage::whereHas('itp', fn ($q) => $q->where('document_id', $doc->id))->first()->reference);
        });
        $this->actingAs($this->actor())->get("/projects/PH-14/documents/{$doc->id}/standard-references?lang=en")
            ->assertOk()->assertSee('Historical standard basis was not captured')->assertDontSee('Frozen at publication');
    }

    public function test_foreign_project_and_revision_requests_cannot_expose_a_snapshot(): void
    {
        [$doc] = $this->scoped(fn () => $this->fixture());
        $other = $this->scoped(fn () => app(DocumentRegister::class)->register($this->project(), $this->actor(), ['discipline' => 'MECH', 'doc_type' => 'DWG', 'title_en' => 'Other', 'title_fa' => 'دیگر']));
        $this->actingAs($this->actor())->get("/projects/PH-14/documents/{$doc->id}/standard-references?revision={$other->current_revision_id}")->assertNotFound();
        $response = $this->get("/projects/BB-2/documents/{$doc->id}/standard-references");
        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee('TEST 99');
    }
}
