<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentFileStore;
use App\Services\DocumentRegister;
use App\Support\CdeState;
use App\Support\FileType;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * File attachments for the document register (SRS FR-DOC-01/02).
 *
 * The expensive failures here are security ones, so that is what most of these
 * tests are about:
 *
 *   1. A stored file must never be reachable without passing auth, entitlement,
 *      membership and RLS. On a multi-company project a leaked drawing is a
 *      contract breach.
 *   2. A user-supplied filename must never influence a filesystem path, and an
 *      arbitrary upload must never be served inline.
 *   3. A published revision is a matter of record; its attachments must be
 *      frozen.
 */
final class DocumentFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        // A real disk, in a scratch root: the point of these tests is what
        // actually lands on the filesystem, which Storage::fake() abstracts
        // away precisely enough to hide a path-traversal bug.
        config(['filesystems.disks.local.root' => storage_path('framework/testing/docfiles')]);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function project(string $code): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    private function store(): DocumentFileStore
    {
        return app(DocumentFileStore::class);
    }

    /** Register a fresh document and return it, inside the tenant scope. */
    private function makeDocument(User $user, Project $project, string $title = 'Foundation plan'): Document
    {
        return app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => app(DocumentRegister::class)->register($project, $user, [
                'discipline' => 'CIVIL',
                'doc_type' => 'DWG',
                'title_en' => $title,
                'title_fa' => 'نقشه فونداسیون',
            ]),
            $project->id,
        );
    }

    private function upload(string $name, string $body = '%PDF-1.4 test'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $body);

        return new UploadedFile($path, $name, null, null, true);
    }

    // ---- storage (FR-DOC-01) ---------------------------------------------

    public function test_a_file_attaches_to_the_current_revision_with_its_hash(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);
        $body = '%PDF-1.4 foundation';

        $file = app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->store()->attach($doc, $user, $this->upload('plan.pdf', $body)),
            $project->id,
        );

        $this->assertSame($doc->currentRevision->id, $file->revision_id);
        $this->assertSame(hash('sha256', $body), $file->sha256);
        $this->assertSame(strlen($body), $file->size_bytes);
        $this->assertSame('pdf', $file->extension);
        $this->assertSame('plan.pdf', $file->original_name);
    }

    public function test_the_stored_path_is_content_addressed_and_ignores_the_filename(): void
    {
        // The single most important property of this module. If the user's
        // filename never reaches the path, path traversal cannot exist here -
        // it is designed out rather than filtered out.
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        $file = app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->store()->attach(
                $doc,
                $user,
                $this->upload('../../../../etc/passwd.pdf', 'hostile'),
            ),
            $project->id,
        );

        $this->assertStringNotContainsString('..', $file->storage_path);
        $this->assertStringNotContainsString('passwd', $file->storage_path);
        $this->assertStringContainsString(hash('sha256', 'hostile'), $file->storage_path);
        // And the displayed name is stripped back to its basename.
        $this->assertSame('passwd.pdf', $file->original_name);

        // The bytes really did land under the disk root, not above it.
        $this->assertTrue(Storage::disk($file->disk)->exists($file->storage_path));
    }

    public function test_identical_bytes_are_stored_once_and_reattaching_is_idempotent(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        [$a, $b] = app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => [
                $this->store()->attach($doc, $user, $this->upload('a.pdf', 'same bytes')),
                $this->store()->attach($doc, $user, $this->upload('b.pdf', 'same bytes')),
            ],
            $project->id,
        );

        // A double-submit must not produce a second row, nor an error the user
        // would learn to work around by uploading again.
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => DocumentFile::where('document_id', $doc->id)->count(),
            $project->id,
        ));
    }

    public function test_an_unlisted_extension_is_refused(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        $this->expectException(ValidationException::class);

        app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->store()->attach($doc, $user, $this->upload('shell.php', '<?php ?>'), 'en'),
            $project->id,
        );
    }

    public function test_svg_and_html_are_not_accepted_at_all(): void
    {
        // They look like documents to a user and like executable script to a
        // browser. An allow-list means we never have to argue about it.
        $this->assertFalse(FileType::isAllowed('svg'));
        $this->assertFalse(FileType::isAllowed('html'));
        $this->assertFalse(FileType::isAllowed('htm'));
        $this->assertFalse(FileType::isAllowed('php'));
    }

    public function test_only_safe_types_are_ever_served_inline(): void
    {
        // Anything a browser might execute must download instead of render.
        foreach (FileType::allowedExtensions() as $ext) {
            if (! FileType::isInlineViewable($ext)) {
                continue;
            }

            $this->assertContains(
                $ext,
                ['pdf', 'png', 'jpg', 'jpeg'],
                "'{$ext}' is served inline but is not a known-safe viewer type",
            );
        }
    }

    public function test_the_recorded_mime_type_comes_from_our_allow_list_not_the_client(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        // A client claiming text/html for a .pdf is exactly the trick this
        // guards against.
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, '%PDF-1.4');
        $hostile = new UploadedFile($path, 'x.pdf', 'text/html', null, true);

        $file = app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->store()->attach($doc, $user, $hostile),
            $project->id,
        );

        $this->assertSame('application/pdf', $file->mime_type);
    }

    // ---- the revision must be open (FR-ENG-04) ---------------------------

    public function test_files_cannot_be_attached_to_a_published_revision(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);
        $ctx = app(TenantContext::class);

        $ctx->runAs($user->company_id, function () use ($doc, $user): void {
            app(DocumentRegister::class)->transition($doc, $user, CdeState::SHARED, 'S2');
            app(DocumentRegister::class)->transition($doc->refresh(), $user, CdeState::PUBLISHED, 'A1');
        }, $project->id);

        $this->expectException(ValidationException::class);

        $ctx->runAs(
            $user->company_id,
            fn () => $this->store()->attach($doc->refresh(), $user, $this->upload('late.pdf'), 'en'),
            $project->id,
        );
    }

    public function test_a_shared_revision_still_accepts_files(): void
    {
        // Review comments routinely arrive as an attachment while a drawing is
        // out for check, so SHARED must stay open.
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);
        $ctx = app(TenantContext::class);

        $file = $ctx->runAs($user->company_id, function () use ($doc, $user) {
            app(DocumentRegister::class)->transition($doc, $user, CdeState::SHARED, 'S2');

            return $this->store()->attach($doc->refresh(), $user, $this->upload('markup.pdf'));
        }, $project->id);

        $this->assertNotNull($file->id);
    }

    public function test_removing_a_file_keeps_bytes_that_another_revision_still_uses(): void
    {
        // Content addressing means blobs are shared. Deleting the bytes because
        // ONE reference went away would silently empty someone else's issued
        // revision - a far worse bug than a little wasted disk.
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $ctx = app(TenantContext::class);
        $docA = $this->makeDocument($user, $project, 'Plan A');
        $docB = $this->makeDocument($user, $project, 'Plan B');

        [$fileA, $fileB] = $ctx->runAs($user->company_id, fn () => [
            $this->store()->attach($docA, $user, $this->upload('a.pdf', 'shared bytes')),
            $this->store()->attach($docB, $user, $this->upload('b.pdf', 'shared bytes')),
        ], $project->id);

        $this->assertSame($fileA->storage_path, $fileB->storage_path);

        $ctx->runAs($user->company_id, fn () => $this->store()->detach($fileA->load('revision')), $project->id);

        $this->assertTrue(
            Storage::disk($fileB->disk)->exists($fileB->storage_path),
            'the shared blob was deleted while another revision still referenced it',
        );
    }

    public function test_verify_detects_bytes_that_changed_on_disk(): void
    {
        // FR-AUD-02: an issued-for-construction drawing that silently changed
        // is the kind of thing a project discovers during a claim.
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        $file = app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->store()->attach($doc, $user, $this->upload('p.pdf', 'original')),
            $project->id,
        );

        $this->assertTrue($this->store()->verify($file));

        file_put_contents(Storage::disk($file->disk)->path($file->storage_path), 'tampered');

        $this->assertFalse($this->store()->verify($file));
    }

    // ---- HTTP: authorisation and isolation --------------------------------

    public function test_a_viewer_cannot_upload(): void
    {
        // tehrani@pmc holds doc.view only. The UI hides the control; the
        // server must refuse it anyway.
        $owner = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($owner, $project);

        $this->actingAs($this->user('tehrani@pmc.example'))
            ->post(
                "/projects/PH-14/documents/{$doc->id}/files?lang=en",
                ['file' => $this->upload('x.pdf')],
                ['Host' => 'preview.arena.site'],
            )
            ->assertStatus(403);
    }

    public function test_a_download_requires_authentication(): void
    {
        $owner = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($owner, $project);

        $file = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => $this->store()->attach($doc, $owner, $this->upload('secret.pdf')),
            $project->id,
        );

        // No public disk, no guessable URL: an anonymous request is bounced to
        // login rather than handed the bytes.
        $this->get("/projects/PH-14/documents/{$doc->id}/files/{$file->id}", ['Host' => 'preview.arena.site'])
            ->assertRedirect();
    }

    public function test_a_foreign_company_cannot_download_a_file(): void
    {
        // hosseini@arvand is on KISH-1, not PH-14. The project itself must 404
        // (a 403 would confirm it exists), and the file with it.
        $owner = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($owner, $project);

        $file = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => $this->store()->attach($doc, $owner, $this->upload('secret.pdf')),
            $project->id,
        );

        $this->actingAs($this->user('hosseini@arvand.example'))
            ->get(
                "/projects/PH-14/documents/{$doc->id}/files/{$file->id}",
                ['Host' => 'preview.arena.site'],
            )
            ->assertStatus(404);
    }

    public function test_a_pdf_is_served_inline_and_a_cad_file_is_forced_to_download(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        [$pdf, $dwg] = app(TenantContext::class)->runAs($user->company_id, fn () => [
            $this->store()->attach($doc, $user, $this->upload('sheet.pdf', 'pdf bytes')),
            $this->store()->attach($doc, $user, $this->upload('model.dwg', 'dwg bytes')),
        ], $project->id);

        $inline = $this->actingAs($user)->get(
            "/projects/PH-14/documents/{$doc->id}/files/{$pdf->id}",
            ['Host' => 'preview.arena.site'],
        );
        $inline->assertOk();
        $this->assertStringContainsString('inline', $inline->headers->get('content-disposition'));
        $this->assertSame('nosniff', $inline->headers->get('x-content-type-options'));

        $attached = $this->actingAs($user)->get(
            "/projects/PH-14/documents/{$doc->id}/files/{$dwg->id}",
            ['Host' => 'preview.arena.site'],
        );
        $attached->assertOk();
        $this->assertStringContainsString('attachment', $attached->headers->get('content-disposition'));
    }

    public function test_the_register_screen_lists_attachments(): void
    {
        $user = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');
        $doc = $this->makeDocument($user, $project);

        app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => $this->store()->attach($doc, $user, $this->upload('ga-drawing.pdf')),
            $project->id,
        );

        $this->actingAs($user)
            ->get('/projects/PH-14/documents?lang=en', ['Host' => 'preview.arena.site'])
            ->assertOk()
            ->assertSee('ga-drawing.pdf')
            ->assertSee('Attach file');
    }

    public function test_a_header_forging_filename_is_neutralised(): void
    {
        // A quote or a CRLF in a filename can forge extra response headers on
        // the way back out through Content-Disposition. The name is TRUNCATED
        // at the control character, not stitched back together: deleting the
        // CRLF would leave "evilX-Injected: 1.pdf", which is safe but still
        // shows the user the attacker's text as a filename.
        $this->assertSame(
            'evil',
            FileType::sanitiseName("evil\r\nX-Injected: 1.pdf"),
        );
        $this->assertSame('report.pdf', FileType::sanitiseName("report.pdf\r\nSet-Cookie: a=b"));
        $this->assertSame('a.pdf', FileType::sanitiseName('"a".pdf'));
        $this->assertSame('passwd', FileType::sanitiseName('../../etc/passwd'));
    }

    public function test_sizes_are_rendered_in_persian_digits(): void
    {
        // FR-I18N-03. A Persian screen showing latin numerals reads as
        // half-finished to the customer this is being sold to.
        $this->assertSame('۱.۵ مگابایت', FileType::humanSize(1572864, 'fa'));
        $this->assertSame('1.5 MB', FileType::humanSize(1572864, 'en'));
    }
}
