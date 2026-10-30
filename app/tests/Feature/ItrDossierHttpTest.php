<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ItrCompilerService;
use App\Services\PdfRenderer;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ItrDossierFixture;
use Tests\TestCase;

/**
 * ADR-048 A.5 slice 1 + A.4h slice 6 — the ITR compiler gets a surface.
 *
 * The compiler has existed since A.5 slice 1 and could only be driven from
 * SQL: compile a dossier, freeze it under a digest, render it to PDF. This is
 * the screen, and it keeps the compiler's own honesty intact — the dossier
 * kind literally says ITR_COMPILATION_NOT_A_CERTIFICATE, and the completeness
 * report enumerates every gap rather than collapsing them into a verdict.
 *
 * What the screen refuses is as important as what it shows: no freeze button
 * on a DRAFT pack (the guard would refuse it, and a button that only works
 * sometimes is the discovery-on-submit pattern the earlier slices argued
 * against), and no pretence that the PDF is the point — without a headless
 * browser this host says so instead of returning an empty document.
 */
final class ItrDossierHttpTest extends TestCase
{
    private User $owner;

    private Project $project;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------ surface

    public function test_the_pack_list_names_the_packs_and_says_nothing_is_frozen(): void
    {
        $response = $this->actingAs($this->owner)->get($this->route());

        $response->assertOk();
        $response->assertSee('id="no-packs"', false);
    }

    public function test_an_opened_pack_shows_the_dossier_and_its_gaps(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);

        $response = $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]));

        $response->assertOk();
        $response->assertSee('TP-ITR-1', false);
        $response->assertSee('id="dossier-kind"', false);
        $response->assertSee('id="completeness"', false);
        $response->assertSee('id="gap-table"', false);
        $response->assertSee('id="freeze-form"', false);
        $response->assertSee('id="no-snapshots"', false);
        // Nothing is frozen yet and nothing is complete: this is a READY pack
        // with one fitted joint and no weld, no NDT and no result.
        $response->assertDontSee('id="no-packs"', false);
        $response->assertDontSee('id="no-gaps"', false);
        $response->assertDontSee('id="draft-note"', false);
    }

    public function test_a_draft_pack_has_no_freeze_button(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner, 'DRAFT');

        $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]))
            ->assertOk()
            ->assertSee('id="draft-note"', false)
            ->assertDontSee('id="freeze-form"', false);
    }

    public function test_the_screen_switches_to_english(): void
    {
        $this->actingAs($this->owner)->get($this->route([], 'en'))
            ->assertOk()->assertSee('ITR dossiers', false);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->route())->assertRedirect(route('login'));
    }

    public function test_someone_outside_the_project_does_not_get_the_screen(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->get($this->route())->assertForbidden();
    }

    // ------------------------------------------------------------------ freeze

    public function test_freezing_stores_a_snapshot_under_a_server_side_digest(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);

        $response = $this->actingAs($this->owner)->post(route('quality.itr.freeze', [
            'projectCode' => 'PH-14', 'lang' => 'fa',
        ]), [
            'test_pack_id' => $pack->id,
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('quality.itr', [
            'projectCode' => 'PH-14', 'lang' => 'fa', 'pack' => $pack->id,
        ]));
        $response->assertSessionHas('status');

        $row = $this->tenant->runAs(
            $this->project->owner_company_id,
            fn () => DB::table('itr_snapshots')->where('test_pack_id', $pack->id)->first(),
            $this->project->id,
        );

        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row->digest);
        $this->assertStringContainsString(substr((string) $row->digest, 0, 12), (string) session('status'));
        $this->assertSame('FROZEN', $row->state);

        // The dossier is trigger-derived, so the kind is not ours to choose.
        $dossier = json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ITR_COMPILATION_NOT_A_CERTIFICATE', $dossier['kind']);
        $this->assertSame((string) $pack->id, $dossier['test_pack_id']);
    }

    public function test_the_frozen_snapshot_shows_on_the_page_and_re_verifies(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $snapshot = app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'fa',
        );

        $response = $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]));

        $response->assertOk();
        $response->assertSee('id="snapshot-table"', false);
        $response->assertSee(substr($snapshot['digest'], 0, 16), false);
        $response->assertSee(__('quality_itr.digest_yes'), false);
        $response->assertDontSee('id="no-snapshots"', false);
        $response->assertDontSee(__('quality_itr.digest_no'), false);
    }

    public function test_freezing_a_draft_pack_is_refused(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner, 'DRAFT');

        $response = $this->actingAs($this->owner)->post(route('quality.itr.freeze', [
            'projectCode' => 'PH-14', 'lang' => 'fa',
        ]), [
            'test_pack_id' => $pack->id,
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->snapshotCount((string) $pack->id));
    }

    /**
     * A double submit is the normal accident on a slow connection. The
     * (project, request_id) uniqueness turns the second one into a refusal
     * rather than a second snapshot of the same intent.
     */
    public function test_the_same_request_id_does_not_freeze_twice(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $requestId = (string) Str::uuid();
        $url = route('quality.itr.freeze', ['projectCode' => 'PH-14', 'lang' => 'fa']);

        $this->actingAs($this->owner)->post($url, [
            'test_pack_id' => $pack->id, 'request_id' => $requestId,
        ])->assertRedirect();
        $this->actingAs($this->owner)->post($url, [
            'test_pack_id' => $pack->id, 'request_id' => $requestId,
        ])->assertSessionHasErrors();

        $this->assertSame(1, $this->snapshotCount((string) $pack->id));
    }

    public function test_freezing_needs_a_uuid_shaped_request_id(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);

        $this->actingAs($this->owner)->post(route('quality.itr.freeze', [
            'projectCode' => 'PH-14', 'lang' => 'fa',
        ]), [
            'test_pack_id' => $pack->id, 'request_id' => 'not-a-uuid',
        ])->assertSessionHasErrors();

        $this->assertSame(0, $this->snapshotCount((string) $pack->id));
    }

    public function test_someone_outside_the_project_cannot_freeze(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->post(route('quality.itr.freeze', [
            'projectCode' => 'PH-14', 'lang' => 'fa',
        ]), [
            'test_pack_id' => $pack->id, 'request_id' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertSame(0, $this->snapshotCount((string) $pack->id));
    }

    // --------------------------------------------------------------------- pdf

    /**
     * The PDF is optional by host, not by design: the snapshot is the
     * deliverable. On a host with no headless browser the route says so with
     * 503 rather than handing back an empty document; on a host that can
     * render, it returns real bytes. Either way the data is never lost.
     */
    public function test_the_pdf_route_never_returns_an_empty_document(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $snapshot = app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'fa',
        );

        $available = app(\App\Services\PdfRenderer::class)->isAvailable();
        $response = $this->actingAs($this->owner)->get(route('quality.itr.pdf', [
            'projectCode' => 'PH-14', 'lang' => 'fa', 'snapshotId' => $snapshot['id'],
        ]));

        if ($available) {
            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
        } else {
            // The generic error page does not echo the message (and should
            // not): what is asserted is the contract — never an empty PDF.
            $response->assertStatus(503);
            $this->assertStringNotContainsString('%PDF', $response->getContent());
        }
    }

    /**
     * The branch above can only be walked on a host that happens to have a
     * browser, which is exactly how a wrong assertion survives a green local
     * run. This one makes the "renderer exists" path deterministic: a stub
     * binary stands in for Chrome, so the route is proven to hand back the
     * renderer's bytes with the right headers on every host. It proves the
     * route; it does not prove Chrome works.
     */
    public function test_the_pdf_route_returns_the_rendered_bytes_when_a_renderer_exists(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $snapshot = app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'fa',
        );

        $stub = $this->stubRenderer();
        $this->app->instance(PdfRenderer::class, $stub);

        try {
            $this->assertTrue($stub->isAvailable(), 'the stub renderer must be reachable');

            $response = $this->actingAs($this->owner)->get(route('quality.itr.pdf', [
                'projectCode' => 'PH-14', 'lang' => 'fa', 'snapshotId' => $snapshot['id'],
            ]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString(
                'inline; filename="itr-'.$snapshot['id'].'.pdf"',
                (string) $response->headers->get('content-disposition'),
            );
            // Symfony normalises and reorders the directives, so assert the
            // two that matter rather than one exact string.
            $cacheControl = (string) $response->headers->get('cache-control');
            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
        } finally {
            @unlink((string) $stub->binary());
        }
    }

    public function test_someone_outside_the_project_cannot_export_a_snapshot(): void
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $snapshot = app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'fa',
        );
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->get(route('quality.itr.pdf', [
            'projectCode' => 'PH-14', 'lang' => 'fa', 'snapshotId' => $snapshot['id'],
        ]))->assertForbidden();
    }

    // ---------------------------------------------------------------- plumbing

    /**
     * A stand-in for headless Chrome: it writes a stub PDF wherever
     * `--print-to-pdf` asks, so the "renderer exists" branch can be tested on
     * a host with no browser. `PdfRenderer` is final and takes its binary list
     * as a constructor argument, so this needs no mocking framework — which is
     * the point, because the class was written to be substituted this way.
     */
    private function stubRenderer(): PdfRenderer
    {
        $script = sys_get_temp_dir().'/epcc-itr-pdf-stub-'.Str::lower(Str::random(8)).'.sh';
        file_put_contents($script, <<<'SH'
#!/bin/sh
out=""
for arg in "$@"; do
  case "$arg" in --print-to-pdf=*) out="${arg#--print-to-pdf=}";; esac
done
printf '%s\n' '%PDF-1.4 stub for the ITR route' > "$out"
i=0
while [ "$i" -lt 40 ]; do
  printf '%s\n' '0123456789abcdefghijklmnopqrstuvwxyz' >> "$out"
  i=$((i + 1))
done
SH);
        chmod($script, 0700);

        return new PdfRenderer(30, [$script]);
    }

    private function snapshotCount(string $packId): int
    {
        return (int) $this->tenant->runAs(
            $this->project->owner_company_id,
            fn (): int => DB::table('itr_snapshots')->where('test_pack_id', $packId)->count(),
            $this->project->id,
        );
    }

    private function route(array $extra = [], string $lang = 'fa'): string
    {
        return route('quality.itr', ['projectCode' => 'PH-14', 'lang' => $lang] + $extra);
    }
}
