<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\ReviewComment;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\ReviewCycle;
use App\Support\CdeState;
use App\Support\CommentCategory;
use App\Support\CommentDisposition;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Review & comment cycle (SRS FR-ENG-06, acceptance journey 3).
 *
 * The single most valuable behaviour in this module is that an open blocking
 * comment PREVENTS PUBLICATION. Without that a comment sheet is a suggestion
 * box, and a drawing with an unanswered Code 1 reaches the site.
 */
final class ReviewCycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function project(string $code): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    private function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** A SHARED document owned by EPC-PARS, ready to be reviewed. */
    private function sharedDocument(): Document
    {
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        return $this->ctx()->runAs(
            $author->company_id,
            fn () => Document::with('currentRevision')
                ->where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail(),
            $project->id,
        );
    }

    // ---- raising comments --------------------------------------------------

    public function test_a_reviewer_can_raise_a_comment_on_a_shared_document(): void
    {
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');

        $comment = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $pmc, CommentCategory::CODE2, 'Clash with tray.', 'Sheet 2'),
            $project->id,
        );

        $this->assertSame($doc->currentRevision->id, $comment->revision_id);
        $this->assertSame($pmc->company_id, $comment->company_id);
        // The originator is recorded at raise time so the "who must answer"
        // question never depends on a later join.
        $this->assertSame($doc->company_id, $comment->originator_company_id);
        $this->assertSame(CommentDisposition::PENDING, $comment->disposition);
    }

    public function test_the_originator_cannot_review_its_own_document(): void
    {
        // Self-approval is exactly the control the owner is paying for.
        $doc = $this->sharedDocument();
        $author = $this->user('rezaei@pars.example');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs(
            $author->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $author, CommentCategory::CODE4, 'Looks fine to me.', null, 'en'),
            $doc->project_id,
        );
    }

    public function test_a_wip_document_is_not_open_for_review(): void
    {
        // Commenting on WIP means reviewing something the author has not
        // offered for review yet.
        $author = $this->user('rezaei@pars.example');
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');

        $wip = $this->ctx()->runAs(
            $author->company_id,
            fn () => Document::with('currentRevision')
                ->where('number', 'PH-14-CIVIL-DWG-0001')->firstOrFail(),
            $project->id,
        );

        $this->assertSame(CdeState::WIP, $wip->currentRevision->cde_state);
        $this->expectException(ValidationException::class);

        $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($wip, $pmc, CommentCategory::CODE1, 'Too early.', null, 'en'),
            $project->id,
        );
    }

    public function test_parallel_reviewers_get_distinct_sequence_numbers(): void
    {
        // FR-ENG-06 requires parallel multi-reviewer. Two companies commenting
        // on the same revision must not collide on (revision_id, seq).
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $owner = $this->user('karimi@nioc.example');
        $project = $this->project('PH-14');

        $a = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $pmc, CommentCategory::CODE3, 'From PMC.'),
            $project->id,
        );
        $b = $this->ctx()->runAs(
            $owner->company_id,
            fn () => app(ReviewCycle::class)->comment($doc->fresh('currentRevision'), $owner, CommentCategory::INFO, 'From owner.'),
            $project->id,
        );

        $this->assertNotSame($a->seq, $b->seq);
        $this->assertSame($a->seq + 1, $b->seq);
    }

    // ---- the gate ----------------------------------------------------------

    public function test_an_open_blocking_comment_prevents_publication(): void
    {
        // THE test of this module.
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $pmc, CommentCategory::CODE1, 'Wrong material class.'),
            $project->id,
        );

        try {
            $this->ctx()->runAs(
                $author->company_id,
                fn () => app(DocumentRegister::class)->transition(
                    $doc->fresh('currentRevision'), $author, CdeState::PUBLISHED, 'A1', 'en',
                ),
                $project->id,
            );
            $this->fail('a drawing with an open Code 1 was published');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cannot publish', implode(' ', $e->validator->errors()->all()));
        }

        // And it really did not move.
        $state = $this->ctx()->runAs(
            $author->company_id,
            fn () => Document::with('currentRevision')->find($doc->id)->currentRevision->cde_state,
            $project->id,
        );
        $this->assertSame(CdeState::SHARED, $state);
    }

    public function test_answering_the_blocking_comments_unblocks_publication(): void
    {
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $comment = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $pmc, CommentCategory::CODE2, 'Relocate support.'),
            $project->id,
        );

        // Clear every pre-seeded blocker too, so this test asserts the gate
        // and not the fixture.
        $this->ctx()->runAs($author->company_id, function () use ($author, $doc): void {
            $open = ReviewComment::where('revision_id', $doc->currentRevision->id)
                ->where('disposition', CommentDisposition::PENDING)->get();

            foreach ($open as $c) {
                app(ReviewCycle::class)->dispose($c, $author, CommentDisposition::ACCEPTED, 'Fixed on Rev C.');
            }
        }, $project->id);

        $revision = $this->ctx()->runAs(
            $author->company_id,
            fn () => app(DocumentRegister::class)->transition(
                $doc->fresh('currentRevision'), $author, CdeState::PUBLISHED, 'A1', 'en',
            ),
            $project->id,
        );

        $this->assertSame(CdeState::PUBLISHED, $revision->cde_state);

        // Re-read INSIDE the tenant scope. A ->fresh() after runAs() closes
        // runs with no bound tenant and returns NULL, which looks exactly like
        // a failed write (HANDOFF §5a).
        $reread = $this->ctx()->runAs(
            $author->company_id,
            fn () => ReviewComment::find($comment->id)->disposition,
            $project->id,
        );
        $this->assertSame(CommentDisposition::ACCEPTED, $reread);
    }

    public function test_a_non_blocking_comment_does_not_prevent_publication(): void
    {
        // Code 4 and INFO carry no obligation. Blocking on them would train
        // users to avoid the comment sheet entirely.
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        // Clear the seeded blockers first.
        $this->ctx()->runAs($author->company_id, function () use ($author, $doc): void {
            foreach (ReviewComment::where('revision_id', $doc->currentRevision->id)
                ->where('disposition', CommentDisposition::PENDING)->get() as $c) {
                app(ReviewCycle::class)->dispose($c, $author, CommentDisposition::NOTED, null);
            }
        }, $project->id);

        $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc->fresh('currentRevision'), $pmc, CommentCategory::INFO, 'FYI only.'),
            $project->id,
        );

        $revision = $this->ctx()->runAs(
            $author->company_id,
            fn () => app(DocumentRegister::class)->transition(
                $doc->fresh('currentRevision'), $author, CdeState::PUBLISHED, 'A1', 'en',
            ),
            $project->id,
        );

        $this->assertSame(CdeState::PUBLISHED, $revision->cde_state);
    }

    public function test_the_blocking_message_names_the_comments(): void
    {
        // "Blocked by 3 comments" sends the user hunting; naming them sends
        // the user to the right rows.
        $doc = $this->sharedDocument();
        $project = $this->project('PH-14');
        $author = $this->user('rezaei@pars.example');

        try {
            $this->ctx()->runAs(
                $author->company_id,
                fn () => app(DocumentRegister::class)->transition(
                    $doc, $author, CdeState::PUBLISHED, 'A1', 'en',
                ),
                $project->id,
            );
            $this->fail('expected the seeded blockers to stop publication');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->validator->errors()->all());
            $this->assertMatchesRegularExpression('/comments [0-9]+(, [0-9]+)+/', $message);
        }
    }

    // ---- dispositions ------------------------------------------------------

    public function test_only_the_originator_may_dispose(): void
    {
        // A reviewer answering its own comment is marking its own homework.
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');

        $comment = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $pmc, CommentCategory::CODE2, 'Please revise.'),
            $project->id,
        );

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->dispose($comment, $pmc, CommentDisposition::ACCEPTED, 'Agreed.', 'en'),
            $project->id,
        );
    }

    public function test_rejecting_a_comment_requires_a_justification(): void
    {
        // "Rejected" with no reason is how a review cycle becomes a dispute.
        $doc = $this->sharedDocument();
        $pmc = $this->user('tehrani@pmc.example');
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $comment = $this->ctx()->runAs(
            $pmc->company_id,
            fn () => app(ReviewCycle::class)->comment($doc, $pmc, CommentCategory::CODE2, 'Change the routing.'),
            $project->id,
        );

        try {
            $this->ctx()->runAs(
                $author->company_id,
                fn () => app(ReviewCycle::class)->dispose($comment, $author, CommentDisposition::REJECTED, '  ', 'en'),
                $project->id,
            );
            $this->fail('a comment was rejected with no justification');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('justification', implode(' ', $e->validator->errors()->all()));
        }

        // Accepting, by contrast, needs no note.
        $ok = $this->ctx()->runAs(
            $author->company_id,
            fn () => app(ReviewCycle::class)->dispose($comment, $author, CommentDisposition::ACCEPTED, null),
            $project->id,
        );
        $this->assertSame(CommentDisposition::ACCEPTED, $ok->disposition);
    }

    public function test_statistics_report_closure(): void
    {
        $doc = $this->sharedDocument();
        $project = $this->project('PH-14');
        $author = $this->user('rezaei@pars.example');

        $stats = $this->ctx()->runAs(
            $author->company_id,
            fn () => app(ReviewCycle::class)->statistics($doc->currentRevision),
            $project->id,
        );

        // The fixture is deliberately mid-cycle: 4 raised, 2 answered.
        $this->assertSame(4, $stats['total']);
        $this->assertSame(2, $stats['pending']);
        $this->assertSame(2, $stats['blocking']);
        $this->assertSame(50, $stats['closure']);
    }

    public function test_a_revision_with_no_comments_reads_as_fully_closed(): void
    {
        // 0% on a clean drawing would be alarming and wrong: nothing is
        // outstanding.
        $author = $this->user('rezaei@pars.example');
        $project = $this->project('PH-14');

        $clean = $this->ctx()->runAs(
            $author->company_id,
            fn () => Document::with('currentRevision')
                ->where('number', 'PH-14-CIVIL-CALC-0001')->firstOrFail(),
            $project->id,
        );

        $stats = $this->ctx()->runAs(
            $author->company_id,
            fn () => app(ReviewCycle::class)->statistics($clean->currentRevision),
            $project->id,
        );

        $this->assertSame(0, $stats['total']);
        $this->assertSame(100, $stats['closure']);
    }

    // ---- categories --------------------------------------------------------

    public function test_the_blocking_categories_are_the_ones_that_stop_work(): void
    {
        $this->assertTrue(CommentCategory::blocksPublication(CommentCategory::HOLD));
        $this->assertTrue(CommentCategory::blocksPublication(CommentCategory::CODE1));
        $this->assertTrue(CommentCategory::blocksPublication(CommentCategory::CODE2));
        // Code 3 is "incorporate, no resubmission" - it must not block.
        $this->assertFalse(CommentCategory::blocksPublication(CommentCategory::CODE3));
        $this->assertFalse(CommentCategory::blocksPublication(CommentCategory::CODE4));
        $this->assertFalse(CommentCategory::blocksPublication(CommentCategory::INFO));
    }

    public function test_every_category_and_disposition_is_bilingual(): void
    {
        // FR-I18N-05. A Persian screen falling back to an English enum name is
        // exactly the half-finished feel this product cannot afford.
        foreach (CommentCategory::all() as $c) {
            foreach (['fa', 'en'] as $lang) {
                $this->assertNotSame($c, CommentCategory::label($c, $lang), "category {$c} has no {$lang} label");
                $this->assertNotSame('', trim(CommentCategory::code($c, $lang)));
            }
            $this->assertNotSame(
                CommentCategory::label($c, 'fa'),
                CommentCategory::label($c, 'en'),
                "category {$c} has the same text in both languages",
            );
        }

        foreach ([CommentDisposition::PENDING, ...CommentDisposition::selectable()] as $d) {
            foreach (['fa', 'en'] as $lang) {
                $this->assertNotSame($d, CommentDisposition::label($d, $lang));
            }
        }
    }

    // ---- HTTP --------------------------------------------------------------

    public function test_the_comment_sheet_renders_for_a_reviewer(): void
    {
        $doc = $this->sharedDocument();

        $this->actingAs($this->user('tehrani@pmc.example'))
            ->get("/projects/PH-14/documents/{$doc->id}/review?lang=en", ['Host' => 'preview.arena.site'])
            ->assertOk()
            ->assertSee('Comment sheet')
            ->assertSee('Raise a comment')
            // The reviewer must NOT be offered the originator's controls.
            ->assertDontSee('Respond');
    }

    public function test_the_sheet_offers_the_originator_dispositions_not_a_comment_box(): void
    {
        $doc = $this->sharedDocument();

        $html = $this->actingAs($this->user('rezaei@pars.example'))
            ->get("/projects/PH-14/documents/{$doc->id}/review?lang=en", ['Host' => 'preview.arena.site'])
            ->assertOk()
            ->assertSee('Respond')
            ->getContent();

        $this->assertStringNotContainsString('Raise a comment', $html);
    }

    public function test_a_viewer_without_doc_review_cannot_post_a_comment(): void
    {
        // site_engineer holds doc.view only.
        $doc = $this->sharedDocument();

        $this->actingAs($this->user('mousavi@sahand.example'))
            ->post(
                "/projects/PH-14/documents/{$doc->id}/review?lang=en",
                ['category' => CommentCategory::CODE1, 'body' => 'Should not land.'],
                ['Host' => 'preview.arena.site'],
            )
            ->assertStatus(403);
    }

    public function test_a_non_participant_cannot_see_the_sheet(): void
    {
        $doc = $this->sharedDocument();

        $this->actingAs($this->user('hosseini@arvand.example'))
            ->get("/projects/PH-14/documents/{$doc->id}/review", ['Host' => 'preview.arena.site'])
            ->assertStatus(404);
    }

    public function test_the_register_shows_the_blocking_count(): void
    {
        // A reviewer should never have to open a document to discover whether
        // it has comments.
        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/PH-14/documents?lang=en', ['Host' => 'preview.arena.site'])
            ->assertOk()
            ->assertSee('blocking');
    }
}
