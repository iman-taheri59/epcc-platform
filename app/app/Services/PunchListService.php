<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Project;
use App\Models\PunchItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\PunchCategory;
use App\Support\PunchState;
use App\Support\TenantType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The punch list (SRS FR-COM-04, acceptance journey 5).
 *
 *   Management contractor walks down a subsystem  ->  raises items with
 *   a category
 *   ->  items assigned to the responsible contractor  ->  contractor fixes
 *   ->  inspector verifies (or rejects)  ->  owner's side closes
 *
 * The role rules come from the SRS visibility matrix (§4.1, "Punch item (A)"):
 *
 *   | OWN           | EPC | PMC    | MC          | TPI   |
 *   | Approve close | Fix | Verify | Raise/Verify| Raise |
 *
 * They are enforced HERE rather than in the controller, so the API, an
 * importer, and the mobile app cannot each invent their own version.
 */
final class PunchListService
{
    /**
     * Tenant types that may verify somebody else's fix.
     *
     * MC here is the MANAGEMENT CONTRACTOR (مدیریت پیمان), the employer's
     * contract administrator - not "Mechanical Completion", which is the
     * milestone being certified. See App\Support\TenantType::MC.
     */
    private const VERIFIER_TYPES = [
        TenantType::PMC, TenantType::MC, TenantType::TPI, TenantType::OWN,
    ];

    /** Tenant types that may raise an item. */
    private const RAISER_TYPES = [
        TenantType::PMC, TenantType::MC, TenantType::TPI, TenantType::OWN, TenantType::EPC,
    ];

    /**
     * Raise a punch item against a subsystem.
     *
     * @throws ValidationException
     */
    public function raise(
        Subsystem $subsystem,
        User $user,
        string $category,
        string $descriptionEn,
        string $descriptionFa,
        ?string $location = null,
        string $lang = 'fa',
    ): PunchItem {
        if (! PunchCategory::isValid($category)) {
            throw ValidationException::withMessages([
                'category' => $lang === 'fa'
                    ? 'دستهٔ نقص معتبر نیست.'
                    : 'That punch category is not valid.',
            ]);
        }

        if (trim($descriptionEn) === '' || trim($descriptionFa) === '') {
            throw ValidationException::withMessages([
                'description' => $lang === 'fa'
                    ? 'شرح نقص به هر دو زبان الزامی است.'
                    : 'A punch item needs a description in both languages.',
            ]);
        }

        $company = Company::findOrFail($user->company_id);

        if (! in_array($company->tenant_type, self::RAISER_TYPES, true)) {
            throw ValidationException::withMessages([
                'company' => $lang === 'fa'
                    ? 'شرکت شما مجاز به ثبت نقص نیست.'
                    : 'Your company type may not raise punch items.',
            ]);
        }

        return DB::transaction(function () use ($subsystem, $user, $category, $descriptionEn, $descriptionFa, $location): PunchItem {
            /*
             * Lock the parent subsystem row to allocate the sequence.
             * `SELECT max(...) FOR UPDATE` is rejected by PostgreSQL when an
             * aggregate is present, and a walkdown is exactly the case where
             * two inspectors file items at the same second.
             */
            Subsystem::whereKey($subsystem->id)->lockForUpdate()->first();

            $project = Project::findOrFail($subsystem->project_id);
            $seq = 1 + (int) PunchItem::where('project_id', $project->id)->max('sequence');

            return PunchItem::create([
                'project_id' => $project->id,
                'subsystem_id' => $subsystem->id,
                'company_id' => $user->company_id,
                'responsible_company_id' => null,
                'number' => sprintf('%s-PL-%04d', $project->code, $seq),
                'sequence' => $seq,
                'category' => $category,
                // Set explicitly. A DB default is NOT reflected back into the
                // model, so isOpen() would read NULL on a freshly created row.
                'state' => PunchState::RAISED,
                'description_en' => $descriptionEn,
                'description_fa' => $descriptionFa,
                'location' => $location,
                'raised_by' => $user->id,
                'raised_at' => now(),
            ]);
        });
    }

    /**
     * Assign the item to the company that has to fix it.
     *
     * @throws ValidationException
     */
    public function assign(PunchItem $item, Company $responsible, string $lang = 'fa'): PunchItem
    {
        $this->requireTransition($item, PunchState::ASSIGNED, $lang);

        $participates = DB::table('project_company')
            ->where('project_id', $item->project_id)
            ->where('company_id', $responsible->id)
            ->exists();

        if (! $participates) {
            throw ValidationException::withMessages([
                'responsible' => $lang === 'fa'
                    ? 'این شرکت در پروژه مشارکت ندارد.'
                    : 'That company does not participate in this project.',
            ]);
        }

        $item->update([
            'responsible_company_id' => $responsible->id,
            'state' => PunchState::ASSIGNED,
        ]);

        return $item->refresh();
    }

    /**
     * The responsible contractor reports the defect fixed.
     *
     * This does NOT close the item. Somebody independent has to agree.
     *
     * @throws ValidationException
     */
    public function markFixed(PunchItem $item, User $user, string $note, string $lang = 'fa'): PunchItem
    {
        $this->requireTransition($item, PunchState::FIXED, $lang);

        if ($item->responsible_company_id !== $user->company_id) {
            throw ValidationException::withMessages([
                'state' => $lang === 'fa'
                    ? 'فقط شرکت مسئول می‌تواند رفع نقص را اعلام کند.'
                    : 'Only the responsible company can report the item fixed.',
            ]);
        }

        if (trim($note) === '') {
            throw ValidationException::withMessages([
                'note' => $lang === 'fa'
                    ? 'شرح اقدام اصلاحی الزامی است.'
                    : 'Say what was done to fix it.',
            ]);
        }

        $item->update([
            'state' => PunchState::FIXED,
            'fixed_by' => $user->id,
            'fixed_at' => now(),
            'fix_note' => $note,
        ]);

        return $item->refresh();
    }

    /**
     * Verify (or reject) a reported fix.
     *
     * @throws ValidationException
     */
    public function verify(PunchItem $item, User $user, bool $accepted, string $note = '', string $lang = 'fa'): PunchItem
    {
        $target = $accepted ? PunchState::VERIFIED : PunchState::REJECTED;
        $this->requireTransition($item, $target, $lang);

        $company = Company::findOrFail($user->company_id);

        /*
         * The contractor cannot verify its own fix. This is the same
         * self-approval control as the review cycle: it is the reason the
         * owner is paying for an MC body at all.
         */
        if ($item->responsible_company_id === $user->company_id) {
            throw ValidationException::withMessages([
                'verify' => $lang === 'fa'
                    ? 'شرکت مسئول نمی‌تواند رفع نقص خود را تأیید کند.'
                    : 'The responsible company cannot verify its own fix.',
            ]);
        }

        if (! in_array($company->tenant_type, self::VERIFIER_TYPES, true)) {
            throw ValidationException::withMessages([
                'verify' => $lang === 'fa'
                    ? 'شرکت شما مجاز به تأیید بازرسی نیست.'
                    : 'Your company type may not verify punch items.',
            ]);
        }

        // A rejection without a reason is how a punch list becomes a dispute.
        if (! $accepted && trim($note) === '') {
            throw ValidationException::withMessages([
                'note' => $lang === 'fa'
                    ? 'دلیل رد باید نوشته شود.'
                    : 'Rejecting a fix requires a written reason.',
            ]);
        }

        $item->update([
            'state' => $target,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'verification_note' => $note !== '' ? $note : null,
        ]);

        return $item->refresh();
    }

    /**
     * Close a verified item. Per the SRS matrix the owner approves the close.
     *
     * @throws ValidationException
     */
    public function close(PunchItem $item, User $user, string $lang = 'fa'): PunchItem
    {
        $this->requireTransition($item, PunchState::CLOSED, $lang);

        $project = Project::findOrFail($item->project_id);

        if ($project->owner_company_id !== $user->company_id) {
            throw ValidationException::withMessages([
                'close' => $lang === 'fa'
                    ? 'بستن نهایی نقص فقط با کارفرما است.'
                    : 'Only the employer can close a punch item.',
            ]);
        }

        $item->update([
            'state' => PunchState::CLOSED,
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);

        return $item->refresh();
    }

    /**
     * @throws ValidationException
     */
    private function requireTransition(PunchItem $item, string $to, string $lang): void
    {
        if (! PunchState::canTransition($item->state, $to)) {
            $from = PunchState::label($item->state)[$lang] ?? $item->state;
            $toLabel = PunchState::label($to)[$lang] ?? $to;

            throw ValidationException::withMessages([
                'state' => $lang === 'fa'
                    ? "تغییر وضعیت از «{$from}» به «{$toLabel}» مجاز نیست."
                    : "A punch item cannot move from \"{$from}\" to \"{$toLabel}\".",
            ]);
        }
    }
}
