<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Support\TenantContext;

/**
 * The thing a sign-off is ABOUT: an issued notification on an ITP stage.
 *
 * Building one is five steps through four services — register an ITP document,
 * add a stage with a point, configure who may draft and issue, create the
 * draft naming its recipients, then issue it — and every step has a rule of
 * its own (the settings row is versioned, the issuing company must own the
 * document, a draft needs an activity window). None of that is what a sign-off
 * test is about, so it lives here, built through the real services the way a
 * project would.
 *
 * The recipient list is a required argument rather than a default, because
 * visibility is the whole point: only the company that issued a notice and the
 * companies it was sent to can see it, so "who was notified" decides who can
 * sign. A fixture that quietly notified everyone would hide exactly the rule
 * these screens are built on.
 */
final class QualitySignOffFixture
{
    /** @param  list<string>  $recipientCompanyIds */
    public static function issuedNotice(
        TenantContext $tenant,
        Project $project,
        User $writer,
        User $employer,
        array $recipientCompanyIds,
        string $point = 'W',
        string $workReference = 'SIGN-OFF FIXTURE WORK',
        bool $issue = true,
    ): string {
        $notifications = app(InspectionNotificationService::class);

        $doc = $tenant->runAs($writer->company_id, fn (): mixed => app(DocumentRegister::class)
            ->register($project, $writer, [
                'discipline' => 'PIPING',
                'doc_type' => 'ITP',
                'title_en' => 'Sign-off fixture ITP',
                'title_fa' => 'سند آزمایشی امضا',
            ]), $project->id);

        $stage = $tenant->runAs($writer->company_id, fn (): string => $notifications
            ->addStage($project, $writer, [
                'revision_id' => $doc->current_revision_id,
                'sequence' => 1,
                'point' => $point,
                'requires_notification' => true,
                'description_en' => 'Sign-off fixture stage',
                'description_fa' => 'مرحلهٔ آزمایشی امضا',
            ]), $project->id);

        // The settings row is versioned, so each configuration must quote the
        // version it read — a hardcoded counter would go stale the moment two
        // notices are built in one test.
        $version = (int) $tenant->runAs($employer->company_id, fn (): int => (int) ($notifications
            ->latest($project)?->version ?? 0), $project->id);

        $tenant->runAs($employer->company_id, fn (): mixed => $notifications->configure($project, $employer, [
            'version' => $version,
            'main_company_id' => $writer->company_id,
            'draft_users' => [$writer->id],
            'issue_users' => [$writer->id],
            'reason' => 'Sign-off fixture settings',
        ]), $project->id);

        $id = $tenant->runAs($writer->company_id, fn (): string => $notifications
            ->createDraft($project, $writer, [
                'itp_revision_id' => $doc->current_revision_id,
                'itp_stage_id' => $stage,
                'work_reference' => $workReference,
                'activity_start_at' => '2026-10-25T09:30:00+03:30',
                'activity_end_at' => '2026-10-25T11:30:00+03:30',
                'location' => 'Fixture site',
                'recipient_company_ids' => $recipientCompanyIds,
            ]), $project->id);

        // Left un-issued on request: a draft is not yet a request for
        // attendance, and a link test needs one to prove that rule.
        if ($issue) {
            $tenant->runAs($writer->company_id, fn (): mixed => $notifications
                ->issue($project, $writer, $id, ['version' => 1]), $project->id);
        }

        return $id;
    }
}
