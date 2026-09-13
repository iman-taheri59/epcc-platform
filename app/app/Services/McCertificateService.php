<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\McCertificate;
use App\Models\Project;
use App\Models\PunchItem;
use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mechanical Completion certificates (SRS FR-COM-05, acceptance journey 5).
 *
 * The acceptance criterion, verbatim from the SRS:
 *
 *   "MCC cannot be issued with open A-items unless Owner-approved exception
 *    is recorded."
 *
 * Both halves are load-bearing. The gate must refuse, AND there must be a way
 * through it that leaves a record - because on a real project the owner
 * sometimes decides an A-item can wait, and a system with no legitimate
 * override just gets bypassed outside the system, which is worse.
 */
final class McCertificateService
{
    /**
     * Start a draft certificate for a subsystem.
     *
     * @throws ValidationException
     */
    public function draft(Subsystem $subsystem, User $user, string $lang = 'fa'): McCertificate
    {
        $existing = McCertificate::where('subsystem_id', $subsystem->id)->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'subsystem' => $lang === 'fa'
                    ? 'برای این زیرسیستم گواهی وجود دارد.'
                    : 'This subsystem already has a certificate.',
            ]);
        }

        return DB::transaction(function () use ($subsystem, $user): McCertificate {
            Subsystem::whereKey($subsystem->id)->lockForUpdate()->first();

            $project = Project::findOrFail($subsystem->project_id);
            $seq = 1 + (int) McCertificate::where('project_id', $project->id)->max('sequence');

            return McCertificate::create([
                'project_id' => $project->id,
                'subsystem_id' => $subsystem->id,
                'company_id' => $user->company_id,
                'number' => sprintf('%s-MCC-%04d', $project->code, $seq),
                'sequence' => $seq,
                'state' => McCertificate::DRAFT,
            ]);
        });
    }

    /**
     * The open A-items standing in the way of this certificate.
     *
     * Always recomputed from the punch table. A cached count is wrong the
     * moment somebody closes an item in another session, and this is the
     * number the gate refuses on.
     *
     * @return \Illuminate\Support\Collection<int,PunchItem>
     */
    public function blockingItems(Subsystem $subsystem)
    {
        return PunchItem::where('subsystem_id', $subsystem->id)
            ->get()
            ->filter(fn (PunchItem $i) => $i->blocksMc())
            ->values();
    }

    /**
     * Record an owner-approved exception, which is the ONLY way to issue a
     * certificate over open A-items.
     *
     * @throws ValidationException
     */
    public function approveException(
        McCertificate $certificate,
        User $user,
        string $reason,
        string $lang = 'fa',
    ): McCertificate {
        if ($certificate->isIssued()) {
            throw ValidationException::withMessages([
                'certificate' => $lang === 'fa'
                    ? 'گواهی صادرشده قابل تغییر نیست.'
                    : 'An issued certificate cannot be changed.',
            ]);
        }

        $project = Project::findOrFail($certificate->project_id);

        /*
         * ONLY the employer may grant this. If the MC body or the contractor
         * could approve its own exception, the gate would be decorative - the
         * party that wants the certificate would simply wave itself through.
         */
        if ($project->owner_company_id !== $user->company_id) {
            throw ValidationException::withMessages([
                'exception' => $lang === 'fa'
                    ? 'تأیید استثنا فقط با کارفرما است.'
                    : 'Only the employer can approve an exception.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => $lang === 'fa'
                    ? 'دلیل تأیید استثنا باید ثبت شود.'
                    : 'An exception must record why it was granted.',
            ]);
        }

        $subsystem = Subsystem::findOrFail($certificate->subsystem_id);

        // Snapshot what was open at the moment of approval. Deriving this later
        // would show today's open items, not the ones the owner accepted.
        $snapshot = $this->blockingItems($subsystem)
            ->map(fn (PunchItem $i) => [
                'number' => $i->number,
                'category' => $i->category,
                'state' => $i->state,
                'description_en' => $i->description_en,
                'description_fa' => $i->description_fa,
            ])
            ->all();

        $certificate->update([
            'exception_approved_by' => $user->id,
            'exception_approved_at' => now(),
            'exception_reason' => $reason,
            'exception_items' => $snapshot,
        ]);

        return $certificate->refresh();
    }

    /**
     * Issue the certificate. This is the gate.
     *
     * @throws ValidationException
     */
    public function issue(McCertificate $certificate, User $user, string $lang = 'fa'): McCertificate
    {
        if ($certificate->isIssued()) {
            throw ValidationException::withMessages([
                'certificate' => $lang === 'fa'
                    ? 'این گواهی قبلاً صادر شده است.'
                    : 'This certificate has already been issued.',
            ]);
        }

        $subsystem = Subsystem::findOrFail($certificate->subsystem_id);
        $blocking = $this->blockingItems($subsystem);

        if ($blocking->isNotEmpty() && ! $certificate->hasApprovedException()) {
            // Name the items. "Blocked by 3 items" sends the reader hunting
            // through the register; the numbers are what they act on.
            $numbers = $blocking->pluck('number')->implode('، ');
            $numbersEn = $blocking->pluck('number')->implode(', ');

            throw ValidationException::withMessages([
                'certificate' => $lang === 'fa'
                    ? "تکمیل مکانیکی با نواقص باز ردهٔ A صادر نمی‌شود: {$numbers}. برای صدور، تأیید استثنا از کارفرما لازم است."
                    : "Mechanical Completion cannot be issued with open A-items: {$numbersEn}. Issuing anyway requires an owner-approved exception.",
            ]);
        }

        /*
         * Freeze the punch-list summary, for the same reason the exception
         * snapshot exists: these three numbers are the technical basis of the
         * handover, and recomputing them on every later download meant an
         * issued certificate's own evidence drifted as the site worked on.
         * The drift flattered - a certificate issued over two open A-items
         * repaired itself into one that looked nearly clean.
         */
        $items = PunchItem::where('subsystem_id', $subsystem->id)->get();

        $certificate->update([
            'state' => McCertificate::ISSUED,
            'issued_by' => $user->id,
            'issued_at' => now(),
            'punch_summary' => [
                'total' => $items->count(),
                'closed' => $items->filter(fn (PunchItem $i) => ! $i->isOpen())->count(),
                'open_blocking' => $blocking->count(),
                'frozen_at' => now()->toIso8601String(),
            ],
        ]);

        return $certificate->refresh();
    }
}
