<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\ProcurementState;
use App\Support\TenantType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تهیه - requisition to purchase order.
 *
 * The rules live here rather than in the controller so the web UI, an
 * importer and a future API cannot each invent their own version. Same
 * reasoning as PunchListService.
 *
 * Segregation of duties is the load-bearing part of this file. A requisition
 * is a request to spend the employer's money, so:
 *
 *   - the person who raises it may not technically approve it
 *   - the person who technically approves it may not commercially approve it
 *
 * Those two rules are separate checks with separate messages, because "you
 * cannot approve your own request" and "the same person cannot sign both
 * approvals" are different failures and a buyer needs to know which one they
 * hit. This mirrors the existing SoD rule cert.approve XOR cert.submit.
 */
final class ProcurementService
{
    /** Company types that may raise and own a requisition. */
    private const BUYER_TYPES = [TenantType::EPC, TenantType::SUB, TenantType::OWN];

    /** Company types whose engineers may sign the technical approval. */
    private const TECHNICAL_APPROVER_TYPES = [
        TenantType::EPC, TenantType::PMC, TenantType::MC, TenantType::OWN,
    ];

    /** Company types that may sign the commercial approval. */
    private const COMMERCIAL_APPROVER_TYPES = [
        TenantType::EPC, TenantType::OWN, TenantType::MC,
    ];

    // -----------------------------------------------------------------------
    // Requisition
    // -----------------------------------------------------------------------

    /**
     * Trusted persistence primitive. Human entry/import/API callers must use
     * MaterialRequisitionEntryService for ADR-018 nomination and bounded validation.
     * Legacy synthetic fixtures are not human authorization endpoints.
     *
     * @param  list<array{item_code:string,description_en:string,description_fa:string,unit:string,quantity:float|string,specification?:string|null,subsystem_id?:string|null}>  $lines
     *
     * @throws ValidationException
     */
    public function raiseRequisition(
        Project $project,
        User $user,
        string $titleEn,
        string $titleFa,
        string $discipline,
        array $lines,
        string $lang = 'fa',
    ): MaterialRequisition {
        $company = Company::findOrFail($user->company_id);

        if (! in_array($company->tenant_type, self::BUYER_TYPES, true)) {
            throw ValidationException::withMessages([
                'company' => $lang === 'fa'
                    ? 'شرکت شما مجاز به ثبت درخواست خرید نیست.'
                    : 'Your company type may not raise a requisition.',
            ]);
        }

        if (trim($titleEn) === '' || trim($titleFa) === '') {
            throw ValidationException::withMessages([
                'title' => $lang === 'fa'
                    ? 'عنوان درخواست به هر دو زبان الزامی است.'
                    : 'A requisition needs a title in both languages.',
            ]);
        }

        /*
         * An empty requisition is not a draft, it is a mistake. Allowing it
         * would put a row in the register that approvers have to open to
         * discover it asks for nothing.
         */
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => $lang === 'fa'
                    ? 'درخواست خرید باید حداقل یک قلم داشته باشد.'
                    : 'A requisition needs at least one line.',
            ]);
        }

        foreach ($lines as $i => $line) {
            if ((float) ($line['quantity'] ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    'lines' => $lang === 'fa'
                        ? sprintf('مقدار قلم %d باید بزرگ‌تر از صفر باشد.', $i + 1)
                        : sprintf('Line %d needs a quantity greater than zero.', $i + 1),
                ]);
            }
        }

        return DB::transaction(function () use ($project, $user, $titleEn, $titleFa, $discipline, $lines): MaterialRequisition {
            // Lock the parent project row to allocate the sequence: two buyers
            // raising at the same second is normal on a live project.
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            // ADR-018: a company-scoped MAX cannot allocate project-wide numbers.
            $seq = 1 + max((int) $lockedProject->material_requisition_sequence,
                (int) MaterialRequisition::where('project_id', $project->id)->max('sequence'));
            $lockedProject->material_requisition_sequence = $seq;
            $lockedProject->save();

            $mr = MaterialRequisition::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'number' => sprintf('%s-MR-%04d', $project->code, $seq),
                'sequence' => $seq,
                'state' => ProcurementState::MR_DRAFT,
                'title_en' => $titleEn,
                'title_fa' => $titleFa,
                'discipline' => $discipline,
                'raised_by' => $user->id,
                'raised_at' => now(),
            ]);

            foreach (array_values($lines) as $i => $line) {
                MaterialRequisitionItem::create([
                    'project_id' => $project->id,
                    'requisition_id' => $mr->id,
                    'subsystem_id' => $line['subsystem_id'] ?? null,
                    'line_no' => $i + 1,
                    'item_code' => $line['item_code'],
                    'description_en' => $line['description_en'],
                    'description_fa' => $line['description_fa'],
                    'unit' => $line['unit'],
                    'quantity' => $line['quantity'],
                    'specification' => $line['specification'] ?? null,
                ]);
            }

            return $mr->refresh();
        });
    }

    /** @throws ValidationException */
    public function submitRequisition(MaterialRequisition $mr, User $user, string $lang = 'fa'): MaterialRequisition
    {
        $this->assertTransition($mr, ProcurementState::MR_SUBMITTED, $lang);

        $mr->update(['state' => ProcurementState::MR_SUBMITTED]);

        return $mr->refresh();
    }

    /**
     * Technical approval: the specification is right.
     *
     * @throws ValidationException
     */
    public function approveTechnically(MaterialRequisition $mr, User $user, string $lang = 'fa'): MaterialRequisition
    {
        $this->assertTransition($mr, ProcurementState::MR_TECHNICAL_APPROVED, $lang);
        $this->assertApproverType($user, self::TECHNICAL_APPROVER_TYPES, $lang);

        // SoD: you cannot approve your own request.
        if ($mr->raised_by === $user->id) {
            throw ValidationException::withMessages([
                'approval' => $lang === 'fa'
                    ? 'درخواست‌کننده نمی‌تواند درخواست خود را تأیید فنی کند.'
                    : 'The person who raised a requisition may not technically approve it.',
            ]);
        }

        $mr->update([
            'state' => ProcurementState::MR_TECHNICAL_APPROVED,
            'technical_approved_by' => $user->id,
            'technical_approved_at' => now(),
        ]);

        return $mr->refresh();
    }

    /**
     * Commercial approval: the money exists.
     *
     * @throws ValidationException
     */
    public function approveCommercially(MaterialRequisition $mr, User $user, string $lang = 'fa'): MaterialRequisition
    {
        $this->assertTransition($mr, ProcurementState::MR_APPROVED, $lang);
        $this->assertApproverType($user, self::COMMERCIAL_APPROVER_TYPES, $lang);

        if ($mr->raised_by === $user->id) {
            throw ValidationException::withMessages([
                'approval' => $lang === 'fa'
                    ? 'درخواست‌کننده نمی‌تواند درخواست خود را تأیید مالی کند.'
                    : 'The person who raised a requisition may not commercially approve it.',
            ]);
        }

        /*
         * The second SoD rule, and a DIFFERENT failure from the one above.
         * One engineer signing both the technical and the commercial approval
         * means a single person moved a purchase from idea to committed spend
         * with no second pair of eyes anywhere. That is the control an auditor
         * actually tests.
         */
        if ($mr->technical_approved_by === $user->id) {
            throw ValidationException::withMessages([
                'approval' => $lang === 'fa'
                    ? 'تأیید فنی و تأیید مالی نمی‌تواند توسط یک نفر انجام شود.'
                    : 'The technical and commercial approvals must be signed by different people.',
            ]);
        }

        $mr->update([
            'state' => ProcurementState::MR_APPROVED,
            'commercial_approved_by' => $user->id,
            'commercial_approved_at' => now(),
        ]);

        return $mr->refresh();
    }

    /** @throws ValidationException */
    public function rejectRequisition(
        MaterialRequisition $mr,
        User $user,
        string $reason,
        string $lang = 'fa',
    ): MaterialRequisition {
        $this->assertTransition($mr, ProcurementState::MR_REJECTED, $lang);

        // A rejection without a reason is unactionable: the buyer cannot tell
        // whether to change the spec, the quantity or the vendor.
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => $lang === 'fa'
                    ? 'دلیل رد درخواست الزامی است.'
                    : 'A rejection must record a reason.',
            ]);
        }

        $mr->update([
            'state' => ProcurementState::MR_REJECTED,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $mr->refresh();
    }

    // -----------------------------------------------------------------------
    // Purchase order
    // -----------------------------------------------------------------------

    /**
     * @param  list<array{item_code:string,description_en:string,description_fa:string,unit:string,quantity:float|string,unit_price?:float|string|null,subsystem_id?:string|null,requisition_item_id?:string|null}>  $lines
     *
     * @throws ValidationException
     */
    public function createPurchaseOrder(
        Project $project,
        User $user,
        Company $vendor,
        string $titleEn,
        string $titleFa,
        array $lines,
        ?MaterialRequisition $requisition = null,
        ?string $currency = null,
        ?string $promisedDate = null,
        string $lang = 'fa',
    ): PurchaseOrder {
        $buyer = Company::findOrFail($user->company_id);

        if (! in_array($buyer->tenant_type, self::BUYER_TYPES, true)) {
            throw ValidationException::withMessages([
                'company' => $lang === 'fa'
                    ? 'شرکت شما مجاز به صدور سفارش خرید نیست.'
                    : 'Your company type may not raise a purchase order.',
            ]);
        }

        /*
         * The order must go to a vendor, and that vendor must be on this
         * project. Without the participation check a buyer could name any
         * company in the database and hand it read access to the PO through
         * the vendor clause in the RLS policy.
         */
        if ($vendor->tenant_type !== TenantType::VEN) {
            throw ValidationException::withMessages([
                'vendor' => $lang === 'fa'
                    ? 'سفارش خرید فقط به شرکت وندور صادر می‌شود.'
                    : 'A purchase order can only be placed with a vendor company.',
            ]);
        }

        $participates = DB::table('project_company')
            ->where('project_id', $project->id)
            ->where('company_id', $vendor->id)
            ->exists();

        if (! $participates) {
            throw ValidationException::withMessages([
                'vendor' => $lang === 'fa'
                    ? 'این وندور در این پروژه مشارکت ندارد.'
                    : 'That vendor is not a participant on this project.',
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => $lang === 'fa'
                    ? 'سفارش خرید باید حداقل یک قلم داشته باشد.'
                    : 'A purchase order needs at least one line.',
            ]);
        }

        // Ordering against an unapproved requisition would defeat both
        // approvals we just spent two methods enforcing.
        if ($requisition !== null && ! $requisition->isOrderable()) {
            throw ValidationException::withMessages([
                'requisition' => $lang === 'fa'
                    ? 'فقط از روی درخواست خرید تأییدشده می‌توان سفارش صادر کرد.'
                    : 'Only an approved requisition can be turned into a purchase order.',
            ]);
        }

        return DB::transaction(function () use (
            $project, $user, $vendor, $titleEn, $titleFa, $lines,
            $requisition, $currency, $promisedDate
        ): PurchaseOrder {
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $seq = 1 + max((int) $lockedProject->purchase_order_sequence,
                (int) PurchaseOrder::where('project_id', $project->id)->max('sequence'));
            $lockedProject->purchase_order_sequence = $seq;
            $lockedProject->save();

            $po = PurchaseOrder::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'vendor_company_id' => $vendor->id,
                'number' => sprintf('%s-PO-%04d', $project->code, $seq),
                'sequence' => $seq,
                'state' => ProcurementState::PO_DRAFT,
                'requisition_id' => $requisition?->id,
                ...($requisition?->material_category !== null ? ['material_category' => $requisition->material_category] : []),
                'title_en' => $titleEn,
                'title_fa' => $titleFa,
                'currency' => $currency,
                'promised_date' => $promisedDate,
            ]);

            $total = 0.0;
            $priced = false;

            foreach (array_values($lines) as $i => $line) {
                $price = $line['unit_price'] ?? null;

                if ($price !== null) {
                    $priced = true;
                    $total += (float) $line['quantity'] * (float) $price;
                }

                PurchaseOrderItem::create([
                    'project_id' => $project->id,
                    'purchase_order_id' => $po->id,
                    'requisition_item_id' => $line['requisition_item_id'] ?? null,
                    'subsystem_id' => $line['subsystem_id'] ?? null,
                    'line_no' => $i + 1,
                    'item_code' => $line['item_code'],
                    'description_en' => $line['description_en'],
                    'description_fa' => $line['description_fa'],
                    'unit' => $line['unit'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $price,
                ]);
            }

            /*
             * Only store a total when at least one line carried a price.
             * Writing 0.00 for an unpriced order would render as "free" in
             * every report, which is worse than an honest blank.
             */
            if ($priced) {
                $po->update(['total_value' => $total]);
            }

            if ($requisition !== null) {
                $requisition->update(['state' => ProcurementState::MR_ORDERED]);
            }

            return $po->refresh();
        });
    }

    /** @throws ValidationException */
    public function issuePurchaseOrder(PurchaseOrder $po, User $user, string $lang = 'fa'): PurchaseOrder
    {
        if (! ProcurementState::poCanTransition($po->state, ProcurementState::PO_ISSUED)) {
            throw ValidationException::withMessages([
                'state' => $lang === 'fa'
                    ? 'این سفارش در وضعیتی نیست که بتوان صادر کرد.'
                    : 'This purchase order cannot be issued from its current state.',
            ]);
        }

        $po->update([
            'state' => ProcurementState::PO_ISSUED,
            'issued_by' => $user->id,
            'issued_at' => now(),
        ]);

        return $po->refresh();
    }

    /**
     * Expediting: record what the vendor now forecasts.
     *
     * Deliberately does NOT overwrite promised_date. The promise is what the
     * contract says and the forecast is what the vendor now admits; keeping
     * both is the entire value of an expediting report, and a system that
     * lets the forecast quietly become the promise makes every order look
     * on time.
     */
    public function forecast(PurchaseOrder $po, string $forecastDate): PurchaseOrder
    {
        $po->update(['forecast_date' => $forecastDate]);

        return $po->refresh();
    }

    /** @throws ValidationException */
    private function assertTransition(MaterialRequisition $mr, string $to, string $lang): void
    {
        if (! ProcurementState::mrCanTransition($mr->state, $to)) {
            throw ValidationException::withMessages([
                'state' => $lang === 'fa'
                    ? sprintf('انتقال از «%s» به «%s» مجاز نیست.',
                        ProcurementState::mrLabel($mr->state)['fa'],
                        ProcurementState::mrLabel($to)['fa'])
                    : sprintf('A requisition cannot move from %s to %s.',
                        ProcurementState::mrLabel($mr->state)['en'],
                        ProcurementState::mrLabel($to)['en']),
            ]);
        }
    }

    /**
     * @param  list<string>  $types
     *
     * @throws ValidationException
     */
    private function assertApproverType(User $user, array $types, string $lang): void
    {
        $company = Company::findOrFail($user->company_id);

        if (! in_array($company->tenant_type, $types, true)) {
            throw ValidationException::withMessages([
                'company' => $lang === 'fa'
                    ? 'نوع شرکت شما مجاز به این تأیید نیست.'
                    : 'Your company type may not sign this approval.',
            ]);
        }
    }
}
