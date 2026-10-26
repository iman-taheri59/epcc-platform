<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\DocumentFileStore;
use App\Services\DocumentRegister;
use App\Support\CdeState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CdeReadDisciplineTest extends TestCase
{
    private User $actor;

    private Project $project;

    private TenantContext $tenant;

    private array $civil;

    private array $piping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->actor = $this->userByEmail('rezaei@pars.example');
        $this->project = $this->tenant->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
        Storage::fake('local');
        $this->civil = $this->fixture('CIVIL');
        $this->piping = $this->fixture('PIPING');
        $this->scope(['CIVIL']);
    }

    private function fixture(string $discipline, bool $published = false): array
    {
        return $this->tenant->runAs($this->actor->company_id, function () use ($discipline, $published) {
            $doc = app(DocumentRegister::class)->register($this->project, $this->actor, ['discipline' => $discipline, 'doc_type' => 'DWG', 'title_en' => 'PRIVATE-'.$discipline.'-TITLE', 'title_fa' => 'عنوان آزمایشی '.$discipline]);
            $f = app(DocumentFileStore::class)->attach($doc, $this->actor, UploadedFile::fake()->createWithContent('PRIVATE-'.$discipline.'-FILE.pdf', '%PDF-1.4 synthetic '.$discipline));
            if ($published) {
                app(DocumentRegister::class)->transition($doc->fresh(), $this->actor, CdeState::SHARED, 'S2');
                app(DocumentRegister::class)->transition($doc->fresh(), $this->actor, CdeState::PUBLISHED, 'A1');
            }

            return [$doc->fresh(), $f];
        }, $this->project->id);
    }

    private function scope(?array $scope, ?User $actor = null): void
    {
        $a = $actor ?? $this->actor;
        $this->tenant->runAs($a->company_id, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $a->id)->update(['discipline_scope' => $scope]), $this->project->id);
    }

    private function url(array $pair, string $action = 'review'): string
    {
        return '/projects/PH-14/documents/'.$pair[0]->id.($action === 'review' ? '/review' : '/files/'.$pair[1]->id);
    }

    private function index(?User $actor = null, string $query = '?lang=en')
    {
        return $this->actingAs($actor ?? $this->actor)->get('/projects/PH-14/documents'.$query, ['Host' => 'preview.arena.site']);
    }

    public function test_register_filters_before_eager_loading_and_counts_only_visible_rows(): void
    {
        DB::enableQueryLog();
        $r = $this->index()->assertOk()->assertSee('PRIVATE-CIVIL-TITLE')->assertDontSee('PRIVATE-PIPING-TITLE')->assertDontSee('PRIVATE-PIPING-FILE');
        $q = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $docs = $r->viewData('documents');
        $this->assertTrue($docs->every(fn ($d) => $d->discipline === 'CIVIL'));
        $this->assertFalse($docs->contains('id', $this->piping[0]->id));
        foreach (CdeState::all() as $state) {
            $this->assertSame($docs->filter(fn ($d) => $d->currentRevision?->cde_state === $state)->count(), $r->viewData('counts')[$state]);
        }
        foreach ($q as $entry) {
            if (str_contains($entry['query'], '"document_files"') || str_contains($entry['query'], '"review_comments"')) {
                $this->assertNotContains($this->piping[1]->revision_id, $entry['bindings']);
            }
        }
    }

    public function test_empty_scope_has_zero_documents_counts_and_dependent_models(): void
    {
        $this->scope([]);
        $r = $this->index()->assertOk()->assertDontSee('PRIVATE-CIVIL-TITLE')->assertDontSee('PRIVATE-PIPING-TITLE');
        $this->assertCount(0, $r->viewData('documents'));
        $this->assertSame(0, array_sum($r->viewData('counts')));
    }

    public function test_null_scope_preserves_existing_unrestricted_reading(): void
    {
        $this->scope(null);
        $this->index()->assertOk()->assertSee('PRIVATE-CIVIL-TITLE')->assertSee('PRIVATE-PIPING-TITLE');
        $this->actingAs($this->actor)->get($this->url($this->piping))->assertOk();
    }

    public function test_allowed_review_and_download_keep_existing_safe_headers(): void
    {
        $this->actingAs($this->actor)->get($this->url($this->civil).'?lang=en')->assertOk()->assertSee('PRIVATE-CIVIL-TITLE');
        $r = $this->get($this->url($this->civil, 'file'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $r->headers->get('Content-Disposition'));
    }

    public function test_out_of_scope_review_does_not_query_comments_or_revision_details(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->actor)->get($this->url($this->piping))->assertNotFound()->assertDontSee('PRIVATE-PIPING-TITLE');
        $q = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        foreach ($q as $entry) {
            $this->assertStringNotContainsString('"review_comments"', $entry['query']);
            $this->assertStringNotContainsString('"document_revisions"', $entry['query']);
        }
    }

    public function test_out_of_scope_download_get_and_head_do_not_touch_storage(): void
    {
        Storage::shouldReceive('disk')->never();
        $this->actingAs($this->actor)->get($this->url($this->piping, 'file'))->assertNotFound();
        $this->head($this->url($this->piping, 'file'))->assertNotFound();
    }

    public function test_wrong_document_file_project_and_invalid_ids_are_opaque_without_storage(): void
    {
        Storage::shouldReceive('disk')->never();
        $this->actingAs($this->actor)->get('/projects/PH-14/documents/'.$this->civil[0]->id.'/files/'.$this->piping[1]->id)->assertNotFound();
        $this->get('/projects/BB-2/documents/'.$this->civil[0]->id.'/review')->assertNotFound();
        $this->get('/projects/PH-14/documents/not-a-uuid/review')->assertNotFound();
        $this->get('/projects/PH-14/documents/'.$this->civil[0]->id.'/files/not-a-uuid')->assertNotFound();
    }

    public function test_query_string_cannot_change_personal_scope_or_acting_company(): void
    {
        $this->index(query: '?lang=en&discipline=PIPING&as=NIOC-AFF&discipline_scope[]=PIPING')->assertOk()->assertSee('PRIVATE-CIVIL-TITLE')->assertDontSee('PRIVATE-PIPING-TITLE');
    }

    public function test_same_cached_user_observes_newly_restricted_scope_and_disabled_account(): void
    {
        $this->scope(null);
        $this->tenant->runAs($this->actor->company_id, fn () => $this->actor->load('company.plan', 'memberships'), $this->project->id);
        $this->index()->assertSee('PRIVATE-PIPING-TITLE');
        $this->scope(['CIVIL']);
        $this->index()->assertDontSee('PRIVATE-PIPING-TITLE');
        $this->tenant->runAs($this->actor->company_id, fn () => User::whereKey($this->actor->id)->update(['is_active' => false]), $this->project->id);
        $this->index()->assertForbidden();
    }

    public function test_bilingual_registers_hide_excluded_titles_and_review_get_and_head_are_closed(): void
    {
        foreach (['fa', 'en'] as $lang) {
            $this->index(query: '?lang='.$lang)->assertOk()->assertDontSee('PRIVATE-PIPING-TITLE')->assertDontSee('عنوان آزمایشی PIPING');
            $this->actingAs($this->actor)->get($this->url($this->piping).'?lang='.$lang)->assertNotFound();
            $this->head($this->url($this->piping).'?lang='.$lang)->assertNotFound();
        }
    }

    public function test_published_foreign_visibility_still_requires_the_original_readers_discipline(): void
    {
        $c = $this->fixture('CIVIL', true);
        $p = $this->fixture('PIPING', true);
        $reader = $this->userByEmail('mousavi@sahand.example');
        $this->index($reader)->assertOk()->assertSee($c[0]->number)->assertDontSee($p[0]->number);
        $this->actingAs($reader)->get($this->url($c))->assertOk();
        Storage::shouldReceive('disk')->never();
        $this->get($this->url($p, 'file'))->assertNotFound();
        $this->get($this->url($this->civil))->assertNotFound();
    }

    public function test_read_correction_creates_no_domain_changes(): void
    {
        $before = $this->tenant->runAs($this->actor->company_id, fn () => Document::orderBy('id')->get()->toJson(), $this->project->id);
        $this->index()->assertOk();
        $this->actingAs($this->actor)->get($this->url($this->civil))->assertOk();
        $after = $this->tenant->runAs($this->actor->company_id,fn () => Document::orderBy('id')->get()->toJson(),$this->project->id);
        $this->assertSame($before,$after);
    }
}
