<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\McCertificate;
use App\Models\Project;
use App\Models\PunchItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\McCertificateService;
use App\Services\PunchListService;
use App\Support\PunchCategory;
use App\Support\PunchState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * QA/QC: punch list and Mechanical Completion (FR-COM-04/05, journey 5).
 */
final class PunchListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    private function punch(): PunchListService
    {
        return app(PunchListService::class);
    }

    private function mcc(): McCertificateService
    {
        return app(McCertificateService::class);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function company(string $code): Company
    {
        return Company::where('code', $code)->firstOrFail();
    }

    private function project(string $code = 'PH-14'): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    /** @return array{0:Subsystem,1:Project} */
    /**
     * The mechanical subsystem that carries the seeded punch list.
     *
     * This used to be Subsystem::first() with no ordering, in ten places
     * across two files. That is not a lookup, it is a bet that the seeder
     * creates this row before any other - and adding fixture rows for the
     * admin register nearly cashed it: every one of these tests would have
     * silently changed subject to a different subsystem and kept passing
     * against the wrong data. Name the row you mean.
     */
    private function subsystem(): array
    {
        $project = $this->project();
        $mc = $this->company('MC-KARAN');

        $sub = $this->ctx()->runAs(
            $mc->id,
            fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
            $project->id,
        );

        return [$sub, $project];
    }

    // ---- the category rule -------------------------------------------------

    public function test_only_category_a_blocks_mechanical_completion(): void
    {
        $this->assertTrue(PunchCategory::blocksMechanicalCompletion('A'));
        $this->assertFalse(PunchCategory::blocksMechanicalCompletion('B'));
        $this->assertFalse(PunchCategory::blocksMechanicalCompletion('C'));

        // B blocks the LATER milestone, which is a different question.
        $this->assertTrue(PunchCategory::blocksReadyForStartUp('B'));
        $this->assertFalse(PunchCategory::blocksReadyForStartUp('C'));
    }

    public function test_a_closed_a_item_blocks_nothing(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');

        $blocking = $this->ctx()->runAs($mc->id, fn () => $this->mcc()->blockingItems($sub), $project->id);

        // The seed closed PH-14-PL-0003, an A-item. It must not appear.
        $this->assertNotContains('PH-14-PL-0003', $blocking->pluck('number')->all());
        $this->assertSame(2, $blocking->count());
    }

    // ---- the lifecycle -----------------------------------------------------

    public function test_the_state_machine_refuses_illegal_jumps(): void
    {
        // Raised straight to closed skips the fix and the verification, which
        // is the whole audit trail.
        $this->assertFalse(PunchState::canTransition(PunchState::RAISED, PunchState::CLOSED));
        $this->assertFalse(PunchState::canTransition(PunchState::ASSIGNED, PunchState::VERIFIED));
        $this->assertFalse(PunchState::canTransition(PunchState::CLOSED, PunchState::ASSIGNED));

        $this->assertTrue(PunchState::canTransition(PunchState::FIXED, PunchState::REJECTED));
        $this->assertTrue(PunchState::canTransition(PunchState::REJECTED, PunchState::ASSIGNED));
    }

    public function test_a_verified_item_is_still_open_until_it_is_closed(): void
    {
        // Verified means the inspector agrees; closed means the owner accepted
        // it into the record. The gate follows the record.
        $this->assertTrue(PunchState::isOpen(PunchState::VERIFIED));
        $this->assertFalse(PunchState::isOpen(PunchState::CLOSED));
    }

    public function test_raising_an_item_numbers_it_per_project(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        // Derived from the current fixture size, not hardcoded: the assertion
        // is "the next number in the project's sequence", and a literal turns
        // that into "the fixture has exactly N items" - a different claim that
        // breaks whenever the fixture grows.
        $expected = sprintf(
            'PH-14-PL-%04d',
            $this->ctx()->runAs($mc->id, fn () => PunchItem::count(), $project->id) + 1,
        );

        $item = $this->ctx()->runAs($mc->id, fn () => $this->punch()->raise(
            $sub, $jafari, PunchCategory::B, 'Gauge missing.', 'گیج نصب نشده.', null, 'en',
        ), $project->id);

        $this->assertSame($expected, $item->number);
        $this->assertSame(PunchState::RAISED, $item->state);
        // The DB default must be reflected in the object, not just the row.
        $this->assertTrue($item->isOpen());
    }

    public function test_an_item_needs_a_description_in_both_languages(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id, fn () => $this->punch()->raise(
            $sub, $jafari, PunchCategory::A, 'English only.', '   ', null, 'en',
        ), $project->id);
    }

    public function test_an_invalid_category_is_refused(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id, fn () => $this->punch()->raise(
            $sub, $jafari, 'Z', 'x', 'ایکس', null, 'en',
        ), $project->id);
    }

    public function test_an_item_cannot_be_assigned_to_a_company_outside_the_project(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');
        $outsider = $this->company('EPC-ARVAND'); // not on PH-14

        $item = $this->ctx()->runAs($mc->id, fn () => $this->punch()->raise(
            $sub, $jafari, PunchCategory::C, 'x', 'ایکس', null, 'en',
        ), $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id, fn () => $this->punch()->assign($item, $outsider, 'en'), $project->id);
    }

    // ---- who may do what ---------------------------------------------------

    public function test_only_the_responsible_company_can_report_a_fix(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        // PH-14-PL-0001 is assigned to EPC-PARS. The MC body must not be able
        // to declare it fixed on the contractor's behalf.
        $item = $this->ctx()->runAs($mc->id,
            fn () => PunchItem::where('number', 'PH-14-PL-0001')->first(), $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->markFixed($item, $jafari, 'done', 'en'), $project->id);
    }

    public function test_a_fix_needs_a_note_saying_what_was_done(): void
    {
        [$sub, $project] = $this->subsystem();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->user('rezaei@pars.example');

        $item = $this->ctx()->runAs($pars->id,
            fn () => PunchItem::where('number', 'PH-14-PL-0001')->first(), $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($pars->id,
            fn () => $this->punch()->markFixed($item, $rezaei, '   ', 'en'), $project->id);
    }

    public function test_the_contractor_cannot_verify_its_own_fix(): void
    {
        // The self-approval control. This is why the owner pays for an MC body.
        //
        // The responsible company here is the CONSULTANT, not the EPC. Using
        // the EPC made this test inert: EPC is not a verifier tenant type, so
        // deleting the self-check still threw - on the type rule - and the test
        // stayed green while the guard it names was gone. PMC is a legitimate
        // verifier, so the ONLY thing that can refuse it is the self-check.
        [$sub, $project] = $this->subsystem();
        $pmc = $this->company('PMC-TEHRAN');
        $mc = $this->company('MC-KARAN');
        $tehrani = $this->user('tehrani@pmc.example');
        $jafari = $this->user('jafari@karan.example');

        $item = $this->ctx()->runAs($mc->id, function () use ($jafari, $sub, $pmc) {
            $i = $this->punch()->raise($sub, $jafari, PunchCategory::A,
                'Valve handwheel seized.', 'فرمان شیر گیر کرده است.', null, 'en');

            return $this->punch()->assign($i, $pmc, 'en');
        }, $project->id);

        $fixed = $this->ctx()->runAs($pmc->id,
            fn () => $this->punch()->markFixed($item->refresh(), $tehrani, 'Freed and lubricated.', 'en'),
            $project->id);

        try {
            $this->ctx()->runAs($pmc->id,
                fn () => $this->punch()->verify($fixed->refresh(), $tehrani, true, 'looks fine', 'en'),
                $project->id);
            $this->fail('a company verified its own fix');
        } catch (ValidationException $e) {
            // Assert on the MESSAGE. expectException() alone cannot tell this
            // refusal apart from the tenant-type refusal.
            $this->assertStringContainsString(
                'cannot verify its own fix',
                $e->validator->errors()->first(),
                'refused for the wrong reason - the self-approval guard may be gone',
            );
        }
    }

    public function test_rejecting_a_fix_requires_a_written_reason(): void
    {
        [$sub, $project] = $this->subsystem();
        $pars = $this->company('EPC-PARS');
        $mc = $this->company('MC-KARAN');
        $rezaei = $this->user('rezaei@pars.example');
        $jafari = $this->user('jafari@karan.example');

        $item = $this->ctx()->runAs($pars->id, function () use ($rezaei) {
            $i = PunchItem::where('number', 'PH-14-PL-0001')->first();

            return $this->punch()->markFixed($i, $rezaei, 'Guard fitted.', 'en');
        }, $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->verify($item->refresh(), $jafari, false, '', 'en'), $project->id);
    }

    public function test_a_rejected_item_goes_back_to_the_contractor(): void
    {
        [$sub, $project] = $this->subsystem();
        $pars = $this->company('EPC-PARS');
        $mc = $this->company('MC-KARAN');
        $rezaei = $this->user('rezaei@pars.example');
        $jafari = $this->user('jafari@karan.example');

        $item = $this->ctx()->runAs($pars->id, function () use ($rezaei) {
            $i = PunchItem::where('number', 'PH-14-PL-0001')->first();

            return $this->punch()->markFixed($i, $rezaei, 'Guard fitted.', 'en');
        }, $project->id);

        $rejected = $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->verify($item->refresh(), $jafari, false, 'Guard is the wrong size.', 'en'),
            $project->id);

        $this->assertSame(PunchState::REJECTED, $rejected->state);
        $this->assertTrue($rejected->isOpen(), 'a rejected item must still block');

        // And it can be re-assigned for another attempt.
        $again = $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->assign($rejected->refresh(), $pars, 'en'), $project->id);

        $this->assertSame(PunchState::ASSIGNED, $again->state);
    }

    public function test_only_the_employer_can_close_an_item(): void
    {
        [$sub, $project] = $this->subsystem();
        $pars = $this->company('EPC-PARS');
        $mc = $this->company('MC-KARAN');
        $rezaei = $this->user('rezaei@pars.example');
        $jafari = $this->user('jafari@karan.example');

        $verified = $this->ctx()->runAs($pars->id, function () use ($rezaei) {
            $i = PunchItem::where('number', 'PH-14-PL-0001')->first();

            return $this->punch()->markFixed($i, $rezaei, 'Guard fitted.', 'en');
        }, $project->id);

        $verified = $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->verify($verified->refresh(), $jafari, true, 'Correct guard.', 'en'),
            $project->id);

        // The MC body verified it, but closing is the owner's decision.
        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->close($verified->refresh(), $jafari, 'en'), $project->id);
    }

    // ---- the MC gate (acceptance journey 5) --------------------------------

    public function test_the_certificate_is_refused_while_an_a_item_is_open(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        $cert = $this->ctx()->runAs($mc->id, fn () => McCertificate::first(), $project->id);

        try {
            $this->ctx()->runAs($mc->id, fn () => $this->mcc()->issue($cert, $jafari, 'en'), $project->id);
            $this->fail('an MC certificate was issued over open A-items');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first();
            // The refusal must NAME the items - a count sends the reader hunting.
            $this->assertStringContainsString('PH-14-PL-0001', $message);
            $this->assertStringContainsString('PH-14-PL-0002', $message);
        }

        $fresh = $this->ctx()->runAs($mc->id, fn () => McCertificate::first(), $project->id);
        $this->assertSame(McCertificate::DRAFT, $fresh->state, 'the certificate must stay a draft');
    }

    public function test_b_and_c_items_do_not_block_the_certificate(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');
        $rezaei = $this->user('rezaei@pars.example');
        $pars = $this->company('EPC-PARS');

        // Close both remaining A-items properly, leaving the B and C open.
        foreach (['PH-14-PL-0001', 'PH-14-PL-0002'] as $number) {
            $item = $this->ctx()->runAs($pars->id, function () use ($number, $rezaei) {
                $i = PunchItem::where('number', $number)->first();

                return $this->punch()->markFixed($i, $rezaei, 'Done.', 'en');
            }, $project->id);

            $item = $this->ctx()->runAs($mc->id,
                fn () => $this->punch()->verify($item->refresh(), $jafari, true, 'Checked.', 'en'), $project->id);

            $this->ctx()->runAs($nioc->id,
                fn () => $this->punch()->close($item->refresh(), $karimi, 'en'), $project->id);
        }

        $cert = $this->ctx()->runAs($mc->id, fn () => McCertificate::first(), $project->id);
        $issued = $this->ctx()->runAs($mc->id, fn () => $this->mcc()->issue($cert, $jafari, 'en'), $project->id);

        $this->assertSame(McCertificate::ISSUED, $issued->state);
        $this->assertNotNull($issued->issued_at);

        // The B item is still open - MC does not require it.
        $stillOpen = $this->ctx()->runAs($mc->id,
            fn () => PunchItem::where('number', 'PH-14-PL-0004')->first(), $project->id);
        $this->assertTrue($stillOpen->isOpen());
    }

    public function test_only_the_employer_can_approve_an_exception(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        $cert = $this->ctx()->runAs($mc->id, fn () => McCertificate::first(), $project->id);

        // If the MC body could wave itself through, the gate is decorative.
        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id,
            fn () => $this->mcc()->approveException($cert, $jafari, 'We are in a hurry.', 'en'), $project->id);
    }

    public function test_an_exception_without_a_reason_is_refused(): void
    {
        [$sub, $project] = $this->subsystem();
        $nioc = $this->company('NIOC-AFF');
        $karimi = $this->user('karimi@nioc.example');

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($nioc->id,
            fn () => $this->mcc()->approveException($cert, $karimi, '   ', 'en'), $project->id);
    }

    public function test_an_owner_approved_exception_opens_the_gate_and_is_recorded(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);

        $withException = $this->ctx()->runAs($nioc->id, fn () => $this->mcc()->approveException(
            $cert, $karimi, 'Both items are scheduled inside the first maintenance window.', 'en',
        ), $project->id);

        $this->assertTrue($withException->hasApprovedException());
        $this->assertSame($karimi->id, $withException->exception_approved_by);

        // The snapshot must record WHICH items were waived, not just that some were.
        $waived = collect($withException->exception_items)->pluck('number')->all();
        $this->assertContains('PH-14-PL-0001', $waived);
        $this->assertContains('PH-14-PL-0002', $waived);

        $issued = $this->ctx()->runAs($mc->id,
            fn () => $this->mcc()->issue($withException->refresh(), $jafari, 'en'), $project->id);

        $this->assertSame(McCertificate::ISSUED, $issued->state);
    }

    public function test_the_exception_snapshot_does_not_drift_when_items_close_later(): void
    {
        [$sub, $project] = $this->subsystem();
        $nioc = $this->company('NIOC-AFF');
        $pars = $this->company('EPC-PARS');
        $mc = $this->company('MC-KARAN');
        $karimi = $this->user('karimi@nioc.example');
        $rezaei = $this->user('rezaei@pars.example');
        $jafari = $this->user('jafari@karan.example');

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);

        $withException = $this->ctx()->runAs($nioc->id, fn () => $this->mcc()->approveException(
            $cert, $karimi, 'Deferred to the maintenance window.', 'en',
        ), $project->id);

        $this->assertCount(2, $withException->exception_items);

        // Now actually close one of the waived items.
        $item = $this->ctx()->runAs($pars->id, function () use ($rezaei) {
            $i = PunchItem::where('number', 'PH-14-PL-0001')->first();

            return $this->punch()->markFixed($i, $rezaei, 'Guard fitted.', 'en');
        }, $project->id);

        $item = $this->ctx()->runAs($mc->id,
            fn () => $this->punch()->verify($item->refresh(), $jafari, true, 'ok', 'en'), $project->id);
        $this->ctx()->runAs($nioc->id,
            fn () => $this->punch()->close($item->refresh(), $karimi, 'en'), $project->id);

        // The certificate must still say what the owner actually waived.
        $reread = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);
        $this->assertCount(2, $reread->exception_items,
            'the exception snapshot changed after the fact - it is evidence, not a live query');
    }

    public function test_an_issued_certificate_cannot_be_changed(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);
        $cert = $this->ctx()->runAs($nioc->id, fn () => $this->mcc()->approveException(
            $cert, $karimi, 'Deferred.', 'en',
        ), $project->id);
        $issued = $this->ctx()->runAs($mc->id,
            fn () => $this->mcc()->issue($cert->refresh(), $jafari, 'en'), $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($nioc->id,
            fn () => $this->mcc()->approveException($issued->refresh(), $karimi, 'Changed my mind.', 'en'),
            $project->id);
    }

    public function test_a_certificate_cannot_be_issued_twice(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $nioc = $this->company('NIOC-AFF');
        $jafari = $this->user('jafari@karan.example');
        $karimi = $this->user('karimi@nioc.example');

        $cert = $this->ctx()->runAs($nioc->id, fn () => McCertificate::first(), $project->id);
        $cert = $this->ctx()->runAs($nioc->id, fn () => $this->mcc()->approveException(
            $cert, $karimi, 'Deferred.', 'en',
        ), $project->id);
        $issued = $this->ctx()->runAs($mc->id,
            fn () => $this->mcc()->issue($cert->refresh(), $jafari, 'en'), $project->id);

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id, fn () => $this->mcc()->issue($issued->refresh(), $jafari, 'en'), $project->id);
    }

    public function test_one_subsystem_has_at_most_one_certificate(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');

        $this->expectException(ValidationException::class);

        $this->ctx()->runAs($mc->id, fn () => $this->mcc()->draft($sub, $jafari, 'en'), $project->id);
    }

    // ---- tenancy -----------------------------------------------------------

    public function test_a_non_participant_sees_no_punch_items(): void
    {
        $project = $this->project();
        $outsider = $this->company('EPC-ARVAND');

        $count = $this->ctx()->runAs($outsider->id, fn () => PunchItem::count(), $project->id);

        $this->assertSame(0, $count);
    }

    public function test_every_participant_sees_the_whole_punch_list(): void
    {
        // A punch list is a shared activity, not private commercial data: the
        // MC body raises against the contractor's work and the owner signs it
        // off. Hiding rows from any of them breaks the workflow.
        $project = $this->project();

        // The point is that every participant sees the SAME list - punch
        // visibility is project-wide, not company-scoped. Pinning a literal
        // count tested the fixture's size instead; the MC body's view is the
        // reference here.
        $expected = $this->ctx()->runAs(
            $this->company('MC-KARAN')->id,
            fn () => PunchItem::count(),
            $project->id,
        );

        $this->assertGreaterThan(1, $expected, 'the fixture has too few punch items to prove anything');

        foreach (['EPC-PARS', 'PMC-TEHRAN', 'NIOC-AFF', 'MC-KARAN'] as $code) {
            $company = $this->company($code);
            $count = $this->ctx()->runAs($company->id, fn () => PunchItem::count(), $project->id);

            $this->assertSame($expected, $count, "{$code} could not see the whole punch list");
        }
    }

    public function test_closure_percentage_counts_only_closed_items(): void
    {
        [$sub, $project] = $this->subsystem();
        $mc = $this->company('MC-KARAN');

        $percent = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail()->closurePercent(), $project->id);

        // 2 of 5 closed.
        $this->assertSame(40, $percent);
    }

    public function test_every_state_and_category_is_bilingual(): void
    {
        foreach ([PunchState::RAISED, PunchState::ASSIGNED, PunchState::FIXED,
            PunchState::VERIFIED, PunchState::CLOSED, PunchState::REJECTED] as $state) {
            $label = PunchState::label($state);
            $this->assertNotSame($state, $label['fa'], "{$state} has no Persian label");
            $this->assertNotSame('', trim($label['en']));
        }

        foreach (PunchCategory::ALL as $category) {
            $label = PunchCategory::label($category);
            $this->assertNotSame($category, $label['fa'], "category {$category} has no Persian label");
        }
    }

    // ---- HTTP surface ------------------------------------------------------

    public function test_the_register_renders_for_every_participant(): void
    {
        foreach (['jafari@karan.example', 'karimi@nioc.example', 'rezaei@pars.example', 'tehrani@pmc.example'] as $email) {
            $user = $this->user($email);
            $response = $this->actingAs($user)->get('/projects/PH-14/punch?lang=en');

            $response->assertOk("{$email} could not open the punch register");
            $response->assertSee('PH-14-PL-0001');
        }
    }

    public function test_a_non_participant_gets_404(): void
    {
        // EPC-ARVAND is on KISH-1, not PH-14.
        $outsider = $this->user('hosseini@arvand.example');

        $this->actingAs($outsider)->get('/projects/PH-14/punch?lang=en')->assertNotFound();
    }

    public function test_the_gate_refusal_is_shown_on_the_page(): void
    {
        $user = $this->user('jafari@karan.example');
        $response = $this->actingAs($user)->get('/projects/PH-14/punch?lang=en');

        $response->assertOk();
        // The page must name the blocking items, not just say "blocked".
        $response->assertSee('Blocked by open A-items');
        $response->assertSee('PH-14-PL-0001');
    }

    public function test_only_the_employer_is_offered_the_exception_override(): void
    {
        // The MC body wants the certificate; showing it the override invites
        // the question. Only the party that may grant it should see it.
        $mcUser = $this->user('jafari@karan.example');
        $this->actingAs($mcUser)->get('/projects/PH-14/punch?lang=en')
            ->assertOk()
            ->assertDontSee('Approve exception (employer)');

        $owner = $this->user('karimi@nioc.example');
        $this->actingAs($owner)->get('/projects/PH-14/punch?lang=en')
            ->assertOk()
            ->assertSee('Approve exception (employer)');
    }

    public function test_the_page_is_bilingual(): void
    {
        $user = $this->user('jafari@karan.example');

        $fa = $this->actingAs($user)->get('/projects/PH-14/punch?lang=fa');
        $fa->assertOk();
        $fa->assertSee('فهرست نواقص', false);
        $fa->assertSee('dir="rtl"', false);

        $en = $this->actingAs($user)->get('/projects/PH-14/punch?lang=en');
        $en->assertSee('dir="ltr"', false);
    }

    public function test_raising_an_item_over_http_works_end_to_end(): void
    {
        $user = $this->user('jafari@karan.example');
        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $pars = $this->company('EPC-PARS');

        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);

        $expectedNumber = sprintf(
            'PH-14-PL-%04d',
            $this->ctx()->runAs($mc->id, fn () => PunchItem::count(), $project->id) + 1,
        );

        $response = $this->actingAs($user)->post('/projects/PH-14/punch?lang=en', [
            'subsystem_id' => $sub->id,
            'category' => 'A',
            'description_en' => 'Drain valve leaking.',
            'description_fa' => 'شیر تخلیه نشتی دارد.',
            'location' => 'Unit 220',
            'responsible_company_id' => $pars->id,
        ]);

        $response->assertRedirect();

        $item = $this->ctx()->runAs($mc->id,
            fn () => PunchItem::where('number', $expectedNumber)->first(), $project->id);

        $this->assertNotNull($item);
        $this->assertSame(PunchState::ASSIGNED, $item->state, 'raising with a responsible company should assign it');
        $this->assertSame($pars->id, $item->responsible_company_id);
    }

    public function test_the_full_journey_five_runs_over_http(): void
    {
        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $jafari = $this->user('jafari@karan.example');
        $rezaei = $this->user('rezaei@pars.example');
        $karimi = $this->user('karimi@nioc.example');

        $ids = $this->ctx()->runAs($mc->id, fn () => PunchItem::whereIn('number',
            ['PH-14-PL-0001', 'PH-14-PL-0002'])->pluck('id', 'number')->all(), $project->id);

        // Contractor reports both fixed, MC verifies, owner closes.
        foreach ($ids as $id) {
            $this->actingAs($rezaei)->post("/projects/PH-14/punch/{$id}?lang=en", [
                'action' => 'fix', 'note' => 'Corrected and re-inspected.',
            ])->assertRedirect();

            $this->actingAs($jafari)->post("/projects/PH-14/punch/{$id}?lang=en", [
                'action' => 'verify', 'note' => 'Witnessed.',
            ])->assertRedirect();

            $this->actingAs($karimi)->post("/projects/PH-14/punch/{$id}?lang=en", [
                'action' => 'close',
            ])->assertRedirect();
        }

        // With no open A-items the certificate issues without an exception.
        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);

        $this->actingAs($jafari)->post("/projects/PH-14/subsystems/{$sub->id}/certificate?lang=en", [
            'action' => 'issue',
        ])->assertRedirect();

        $cert = $this->ctx()->runAs($mc->id, fn () => McCertificate::first(), $project->id);

        $this->assertSame(McCertificate::ISSUED, $cert->state);
        $this->assertFalse($cert->hasApprovedException(),
            'the certificate should have issued on merit, not through an exception');
    }

    public function test_a_viewer_without_the_capability_cannot_raise_an_item(): void
    {
        // The site engineer has no punch capability at all.
        $user = $this->user('mousavi@sahand.example');
        $project = $this->project();
        $mc = $this->company('MC-KARAN');
        $sub = $this->ctx()->runAs($mc->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $project->id);

        $this->actingAs($user)->post('/projects/PH-14/punch?lang=en', [
            'subsystem_id' => $sub->id,
            'category' => 'C',
            'description_en' => 'x',
            'description_fa' => 'ایکس',
        ])->assertForbidden();
    }
}
