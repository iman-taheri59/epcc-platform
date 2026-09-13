<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Document;
use App\Models\Project;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\TransmittalRecipient;
use App\Models\User;
use App\Support\CdeState;
use App\Support\TransmittalPurpose;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transmittal management (SRS FR-ENG-05, journey 3).
 *
 * A transmittal is EVIDENCE. On an EPCC contract it settles "when did you have
 * the drawing?", which is the question a delay claim turns on. Everything here
 * follows from that:
 *
 *   - Once issued it cannot be altered. Not the items, not the recipients.
 *   - It records what was true AT ISSUE, not what is true now.
 *   - Each recipient acknowledges separately, and only for itself.
 */
final class TransmittalService
{
    /** Draft a transmittal. Items and recipients are added before issuing. */
    public function draft(
        Project $project,
        User $user,
        string $subjectEn,
        string $subjectFa,
        string $purpose,
        ?string $notes = null,
        string $lang = 'fa',
        string $kind = Transmittal::CONTRACTOR,
        ?Company $vendor = null,
        ?string $vendorReference = null,
    ): Transmittal {
        if (! TransmittalPurpose::isValid($purpose)) {
            throw ValidationException::withMessages([
                'purpose' => $lang === 'fa' ? 'هدف ارسال معتبر نیست.' : 'That transmittal purpose is not valid.',
            ]);
        }

        if (! in_array($kind, [Transmittal::CONTRACTOR, Transmittal::VENDOR], true)) {
            throw ValidationException::withMessages([
                'kind' => $lang === 'fa' ? 'نوع نامه معتبر نیست.' : 'That transmittal kind is not valid.',
            ]);
        }

        if ($kind === Transmittal::VENDOR) {
            if ($vendor === null) {
                throw ValidationException::withMessages([
                    'vendor' => $lang === 'fa'
                        ? 'برای نامهٔ وندور باید شرکت وندور مشخص شود.'
                        : 'A vendor transmittal must name the vendor.',
                ]);
            }

            // The vendor is the supplier, not the contractor recording the
            // letter. Allowing them to coincide would let a contractor file
            // its own submissions in the vendor stream and quietly drop them
            // out of the contractual count.
            if ($vendor->id === $user->company_id) {
                throw ValidationException::withMessages([
                    'vendor' => $lang === 'fa'
                        ? 'شرکت ثبت‌کننده نمی‌تواند وندور همان نامه باشد.'
                        : 'The recording company cannot also be the vendor.',
                ]);
            }
        } elseif ($vendor !== null) {
            throw ValidationException::withMessages([
                'vendor' => $lang === 'fa'
                    ? 'نامهٔ پیمانکار نمی‌تواند وندور داشته باشد.'
                    : 'A contractor transmittal cannot name a vendor.',
            ]);
        }

        return DB::transaction(function () use (
            $project, $user, $subjectEn, $subjectFa, $purpose, $notes, $kind, $vendor, $vendorReference
        ): Transmittal {
            /*
             * Two INDEPENDENT counters, one per stream, so the contractual
             * series is not perforated by vendor letters. Scoped by kind:
             * PH-14-TR-0007 and PH-14-VTR-0007 can both exist.
             *
             * Locking rule: `SELECT max(...) FOR UPDATE` is rejected by
             * PostgreSQL with an aggregate, so the PARENT row is locked
             * instead - see the note in DocumentRegister.
             */
            Project::whereKey($project->id)->lockForUpdate()->first();

            $seq = 1 + (int) Transmittal::where('project_id', $project->id)
                ->where('kind', $kind)
                ->max('sequence');

            $prefix = $kind === Transmittal::VENDOR ? 'VTR' : 'TR';

            return Transmittal::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'kind' => $kind,
                'vendor_company_id' => $vendor?->id,
                'vendor_reference' => $vendorReference,
                'number' => sprintf('%s-%s-%04d', strtoupper($project->code), $prefix, $seq),
                'sequence' => $seq,
                'subject_en' => $subjectEn,
                'subject_fa' => $subjectFa,
                'purpose' => $purpose,
                'status' => Transmittal::DRAFT,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Put a document's CURRENT revision on a draft transmittal.
     *
     * @throws ValidationException
     */
    public function addItem(Transmittal $transmittal, Document $document, string $lang = 'fa'): TransmittalItem
    {
        $this->assertDraft($transmittal, $lang);

        $revision = $document->currentRevision;

        if ($revision === null) {
            throw ValidationException::withMessages([
                'document' => $lang === 'fa' ? 'این مدرک بازنگری ندارد.' : 'That document has no revision.',
            ]);
        }

        // Transmitting WIP means formally issuing work the author has not
        // finished. If it is ready to send, it is ready to be SHARED.
        if ($revision->cde_state === CdeState::WIP) {
            throw ValidationException::withMessages([
                'document' => $lang === 'fa'
                    ? 'مدرک «در دست تهیه» قابل ارسال رسمی نیست؛ ابتدا آن را برای بررسی ارسال کنید.'
                    : 'A work-in-progress document cannot be formally transmitted; share it for review first.',
            ]);
        }

        $exists = TransmittalItem::where('transmittal_id', $transmittal->id)
            ->where('revision_id', $revision->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'document' => $lang === 'fa'
                    ? 'این بازنگری قبلاً به این نامه افزوده شده است.'
                    : 'That revision is already on this transmittal.',
            ]);
        }

        return TransmittalItem::create([
            'transmittal_id' => $transmittal->id,
            'project_id' => $transmittal->project_id,
            'company_id' => $transmittal->company_id,
            'document_id' => $document->id,
            'revision_id' => $revision->id,
            // Snapshot. See the model for why.
            'number_at_issue' => $document->number,
            'revision_at_issue' => $revision->revision,
            'state_at_issue' => $revision->cde_state,
            'suitability_at_issue' => $revision->suitability,
        ]);
    }

    /**
     * Roll a VENDOR transmittal's documents up into a contractor transmittal.
     *
     * This is the everyday motion the separation exists to support: the vendor
     * sends the EPC a set of drawings, the EPC reviews them and forwards some
     * or all of them to the Employer under its OWN contractual letter. The
     * chain must survive, because when the client asks "where did this
     * datasheet come from" the answer has to be a document, not a memory.
     *
     * Each created item records `source_item_id`, so the vendor letter it came
     * from is recoverable forever - even after the vendor issues a later
     * revision.
     *
     * @return list<TransmittalItem> the items added to $target
     *
     * @throws ValidationException
     */
    public function rollUp(
        Transmittal $vendorTransmittal,
        Transmittal $target,
        string $lang = 'fa',
    ): array {
        $this->assertDraft($target, $lang);

        if (! $vendorTransmittal->isVendor()) {
            throw ValidationException::withMessages([
                'source' => $lang === 'fa'
                    ? 'فقط نامهٔ وندور قابل انتقال به نامهٔ پیمانکار است.'
                    : 'Only a vendor transmittal can be rolled up.',
            ]);
        }

        if (! $target->isContractor()) {
            throw ValidationException::withMessages([
                'target' => $lang === 'fa'
                    ? 'مقصد باید نامهٔ پیمانکار باشد.'
                    : 'The target must be a contractor transmittal.',
            ]);
        }

        // Forwarding a letter the vendor has not formally issued would put the
        // EPC's name on a draft it never actually received.
        if ($vendorTransmittal->issued_at === null) {
            throw ValidationException::withMessages([
                'source' => $lang === 'fa'
                    ? 'نامهٔ وندور هنوز صادر نشده است.'
                    : 'That vendor transmittal has not been issued yet.',
            ]);
        }

        if ($vendorTransmittal->project_id !== $target->project_id) {
            throw ValidationException::withMessages([
                'target' => $lang === 'fa'
                    ? 'هر دو نامه باید در یک پروژه باشند.'
                    : 'Both transmittals must belong to the same project.',
            ]);
        }

        return DB::transaction(function () use ($vendorTransmittal, $target): array {
            $created = [];

            foreach ($vendorTransmittal->items()->orderBy('created_at')->get() as $source) {
                // Idempotent: rolling the same vendor letter up twice must not
                // duplicate its documents on the target.
                $exists = TransmittalItem::where('transmittal_id', $target->id)
                    ->where('revision_id', $source->revision_id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                /*
                 * The snapshot is copied from the SOURCE ITEM, not re-read from
                 * the document. What the EPC forwards is what the vendor
                 * actually sent; if the vendor has since issued Rev C, that is
                 * a different letter, not a silent upgrade of this one.
                 */
                $created[] = TransmittalItem::create([
                    'transmittal_id' => $target->id,
                    'project_id' => $target->project_id,
                    'company_id' => $target->company_id,
                    'document_id' => $source->document_id,
                    'revision_id' => $source->revision_id,
                    'number_at_issue' => $source->number_at_issue,
                    'revision_at_issue' => $source->revision_at_issue,
                    'state_at_issue' => $source->state_at_issue,
                    'suitability_at_issue' => $source->suitability_at_issue,
                    'source_item_id' => $source->id,
                ]);
            }

            return $created;
        });
    }

    /** Add a receiving company to a draft transmittal. */
    public function addRecipient(Transmittal $transmittal, Company $company, string $lang = 'fa'): TransmittalRecipient
    {
        $this->assertDraft($transmittal, $lang);

        if ($company->id === $transmittal->company_id) {
            throw ValidationException::withMessages([
                'recipient' => $lang === 'fa'
                    ? 'فرستنده نمی‌تواند گیرندهٔ نامهٔ خود باشد.'
                    : 'The sender cannot be a recipient of its own transmittal.',
            ]);
        }

        // A letter to a company that is not on the project is not a
        // transmittal, it is a leak.
        $participates = DB::table('project_company')
            ->where('project_id', $transmittal->project_id)
            ->where('company_id', $company->id)
            ->exists()
            || Project::whereKey($transmittal->project_id)
                ->where('owner_company_id', $company->id)->exists();

        if (! $participates) {
            throw ValidationException::withMessages([
                'recipient' => $lang === 'fa'
                    ? 'این شرکت در پروژه مشارکت ندارد.'
                    : 'That company does not participate in this project.',
            ]);
        }

        $exists = TransmittalRecipient::where('transmittal_id', $transmittal->id)
            ->where('recipient_company_id', $company->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'recipient' => $lang === 'fa'
                    ? 'این شرکت قبلاً در فهرست گیرندگان است.'
                    : 'That company is already a recipient.',
            ]);
        }

        return TransmittalRecipient::create([
            'transmittal_id' => $transmittal->id,
            'project_id' => $transmittal->project_id,
            'company_id' => $transmittal->company_id,
            'recipient_company_id' => $company->id,
        ]);
    }

    /**
     * Issue the transmittal. After this it is a record, not a document.
     *
     * @throws ValidationException
     */
    public function issue(Transmittal $transmittal, User $user, string $lang = 'fa'): Transmittal
    {
        $this->assertDraft($transmittal, $lang);

        $items = TransmittalItem::where('transmittal_id', $transmittal->id)->count();
        $recipients = TransmittalRecipient::where('transmittal_id', $transmittal->id)->count();

        if ($items === 0) {
            throw ValidationException::withMessages([
                'items' => $lang === 'fa'
                    ? 'نامهٔ بدون مدرک قابل صدور نیست.'
                    : 'A transmittal with no documents cannot be issued.',
            ]);
        }

        if ($recipients === 0) {
            throw ValidationException::withMessages([
                'recipients' => $lang === 'fa'
                    ? 'نامهٔ بدون گیرنده قابل صدور نیست.'
                    : 'A transmittal with no recipients cannot be issued.',
            ]);
        }

        return DB::transaction(function () use ($transmittal, $user): Transmittal {
            $now = now();
            $days = TransmittalPurpose::defaultDays($transmittal->purpose);

            $transmittal->update([
                'status' => Transmittal::ISSUED,
                'issued_by' => $user->id,
                'issued_at' => $now,
                // Information-only letters get no clock at all: overdue noise
                // on things nobody must answer trains people to ignore the
                // whole list.
                'due_at' => TransmittalPurpose::requiresAcknowledgement($transmittal->purpose)
                    ? $now->copy()->addDays($days)
                    : null,
            ]);

            return $transmittal->refresh();
        });
    }

    /**
     * A recipient acknowledges receipt.
     *
     * @throws ValidationException
     */
    public function acknowledge(
        Transmittal $transmittal,
        User $user,
        ?string $note = null,
        string $lang = 'fa',
    ): TransmittalRecipient {
        if (! $transmittal->isIssued()) {
            throw ValidationException::withMessages([
                'acknowledge' => $lang === 'fa'
                    ? 'نامهٔ پیش‌نویس قابل تأیید دریافت نیست.'
                    : 'A draft transmittal cannot be acknowledged.',
            ]);
        }

        $row = TransmittalRecipient::where('transmittal_id', $transmittal->id)
            ->where('recipient_company_id', $user->company_id)
            ->first();

        // Only a named recipient can acknowledge, and only for itself. The
        // employer can READ every letter on its project (RLS allows that), but
        // reading is not receiving.
        if ($row === null) {
            throw ValidationException::withMessages([
                'acknowledge' => $lang === 'fa'
                    ? 'شرکت شما در فهرست گیرندگان این نامه نیست.'
                    : 'Your company is not a recipient of this transmittal.',
            ]);
        }

        if ($row->hasAcknowledged()) {
            // Idempotent: a double-click must not overwrite the original
            // timestamp, which is the entire evidentiary value of the row.
            return $row;
        }

        $row->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $user->id,
            'acknowledgement_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ]);

        return $row->refresh();
    }

    /**
     * Outstanding acknowledgements for a project (FR-ENG-05 overdue tracking).
     *
     * @return Collection<int,Transmittal>
     */
    public function overdue(Project $project): Collection
    {
        return Transmittal::with(['recipients.company', 'sender'])
            ->where('project_id', $project->id)
            ->where('status', Transmittal::ISSUED)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get()
            ->filter(fn (Transmittal $t) => $t->isOverdue())
            ->values();
    }

    private function assertDraft(Transmittal $transmittal, string $lang): void
    {
        if ($transmittal->isDraft()) {
            return;
        }

        // The whole point. A letter that can be edited after it was sent
        // proves nothing about what was sent.
        throw ValidationException::withMessages([
            'status' => $lang === 'fa'
                ? 'نامهٔ صادرشده قابل تغییر نیست؛ نامهٔ جدیدی صادر کنید.'
                : 'An issued transmittal cannot be changed; issue a new one.',
        ]);
    }
}
