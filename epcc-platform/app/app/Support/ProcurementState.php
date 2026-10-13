<?php

declare(strict_types=1);

namespace App\Support;

/**
 * States for a material requisition and a purchase order, plus the inspection
 * verdict. Three small vocabularies in one file because they are read
 * together and are meaningless apart.
 *
 * The requisition has TWO approvals, and they are separate states rather than
 * two booleans on one APPROVED state. On a real MR the engineer confirms the
 * specification is right and the project manager confirms the budget exists.
 * Either can refuse for a reason the other is not competent to judge, so
 * "technically approved, awaiting commercial" is a real condition that a
 * buyer sits in for days and must be able to see.
 */
final class ProcurementState
{
    // ---- Material requisition ---------------------------------------------

    public const MR_DRAFT = 'DRAFT';
    public const MR_SUBMITTED = 'SUBMITTED';
    public const MR_TECHNICAL_APPROVED = 'TECHNICAL_APPROVED';
    public const MR_APPROVED = 'APPROVED';
    public const MR_REJECTED = 'REJECTED';
    /** A PO has been raised against it; the MR is finished. */
    public const MR_ORDERED = 'ORDERED';

    public const MR_ALL = [
        self::MR_DRAFT, self::MR_SUBMITTED, self::MR_TECHNICAL_APPROVED,
        self::MR_APPROVED, self::MR_REJECTED, self::MR_ORDERED,
    ];

    /**
     * Legal transitions. A rejected MR goes back to DRAFT to be corrected and
     * resubmitted - it is not a dead end, because the usual reason for
     * rejection is a wrong quantity somebody then fixes.
     *
     * @var array<string,list<string>>
     */
    public const MR_TRANSITIONS = [
        self::MR_DRAFT => [self::MR_SUBMITTED],
        self::MR_SUBMITTED => [self::MR_TECHNICAL_APPROVED, self::MR_REJECTED],
        self::MR_TECHNICAL_APPROVED => [self::MR_APPROVED, self::MR_REJECTED],
        self::MR_APPROVED => [self::MR_ORDERED],
        self::MR_REJECTED => [self::MR_DRAFT],
        self::MR_ORDERED => [],
    ];

    public static function mrCanTransition(string $from, string $to): bool
    {
        return in_array($to, self::MR_TRANSITIONS[$from] ?? [], true);
    }

    /** @return array{fa:string,en:string} */
    public static function mrLabel(string $state): array
    {
        return match ($state) {
            self::MR_DRAFT => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
            self::MR_SUBMITTED => ['fa' => 'ارسال‌شده', 'en' => 'Submitted'],
            self::MR_TECHNICAL_APPROVED => ['fa' => 'تأیید فنی', 'en' => 'Technically approved'],
            self::MR_APPROVED => ['fa' => 'تأیید نهایی', 'en' => 'Approved'],
            self::MR_REJECTED => ['fa' => 'رد شده', 'en' => 'Rejected'],
            self::MR_ORDERED => ['fa' => 'سفارش داده شد', 'en' => 'Ordered'],
            default => ['fa' => $state, 'en' => $state],
        };
    }

    // ---- Purchase order ----------------------------------------------------

    public const PO_DRAFT = 'DRAFT';
    public const PO_ISSUED = 'ISSUED';
    /** Some but not all of the ordered quantity has been received. */
    public const PO_PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    public const PO_RECEIVED = 'RECEIVED';
    public const PO_CANCELLED = 'CANCELLED';

    public const PO_ALL = [
        self::PO_DRAFT, self::PO_ISSUED, self::PO_PARTIALLY_RECEIVED,
        self::PO_RECEIVED, self::PO_CANCELLED,
    ];

    /**
     * PARTIALLY_RECEIVED -> ISSUED is deliberately absent but
     * PARTIALLY_RECEIVED -> PARTIALLY_RECEIVED is not needed either: receipt
     * progress is recomputed from the ledger, so the state is derived and
     * re-set, never nudged.
     *
     * @var array<string,list<string>>
     */
    public const PO_TRANSITIONS = [
        self::PO_DRAFT => [self::PO_ISSUED, self::PO_CANCELLED],
        self::PO_ISSUED => [self::PO_PARTIALLY_RECEIVED, self::PO_RECEIVED, self::PO_CANCELLED],
        self::PO_PARTIALLY_RECEIVED => [self::PO_RECEIVED, self::PO_CANCELLED],
        self::PO_RECEIVED => [],
        self::PO_CANCELLED => [],
    ];

    public static function poCanTransition(string $from, string $to): bool
    {
        return in_array($to, self::PO_TRANSITIONS[$from] ?? [], true);
    }

    /** Only an issued order can take deliveries. */
    public static function poAcceptsReceipts(string $state): bool
    {
        return in_array($state, [self::PO_ISSUED, self::PO_PARTIALLY_RECEIVED], true);
    }

    /** @return array{fa:string,en:string} */
    public static function poLabel(string $state): array
    {
        return match ($state) {
            self::PO_DRAFT => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
            self::PO_ISSUED => ['fa' => 'صادرشده', 'en' => 'Issued'],
            self::PO_PARTIALLY_RECEIVED => ['fa' => 'دریافت جزئی', 'en' => 'Partially received'],
            self::PO_RECEIVED => ['fa' => 'دریافت کامل', 'en' => 'Fully received'],
            self::PO_CANCELLED => ['fa' => 'لغو شده', 'en' => 'Cancelled'],
            default => ['fa' => $state, 'en' => $state],
        };
    }

    // ---- Inspection --------------------------------------------------------

    public const INSPECTION_PASS = 'PASS';
    public const INSPECTION_FAIL = 'FAIL';
    /** Accepted with a recorded reservation - very common on site. */
    public const INSPECTION_CONDITIONAL = 'CONDITIONAL';

    public const INSPECTION_ALL = [
        self::INSPECTION_PASS, self::INSPECTION_FAIL, self::INSPECTION_CONDITIONAL,
    ];

    public const STAGE_WORKS = 'WORKS';
    public const STAGE_PRE_SHIPMENT = 'PRE_SHIPMENT';
    public const STAGE_ON_ARRIVAL = 'ON_ARRIVAL';

    public const STAGE_ALL = [self::STAGE_WORKS, self::STAGE_PRE_SHIPMENT, self::STAGE_ON_ARRIVAL];

    /**
     * Inspection is ADVISORY here, by the user's explicit instruction: a
     * failed or missing inspection does NOT block a goods receipt. That
     * matches how sites actually run - material arrives at 2am and the TPI
     * signs on Sunday - and a system that refuses the receipt is a system
     * that gets bypassed with a paper note nobody ever types in.
     *
     * The price of advisory is that "received but not passed" has to be
     * VISIBLE, or it is just silence. This predicate is what every warehouse
     * screen uses to raise the warning, and what the MC report counts.
     */
    public static function needsAttention(?string $result): bool
    {
        return $result !== self::INSPECTION_PASS;
    }

    /** @return array{fa:string,en:string} */
    public static function inspectionLabel(?string $result): array
    {
        return match ($result) {
            self::INSPECTION_PASS => ['fa' => 'قبول', 'en' => 'Passed'],
            self::INSPECTION_FAIL => ['fa' => 'مردود', 'en' => 'Failed'],
            self::INSPECTION_CONDITIONAL => ['fa' => 'قبول مشروط', 'en' => 'Conditional'],
            null => ['fa' => 'بازرسی نشده', 'en' => 'Not inspected'],
            default => ['fa' => (string) $result, 'en' => (string) $result],
        };
    }

    /** @return array{fa:string,en:string} */
    public static function stageLabel(string $stage): array
    {
        return match ($stage) {
            self::STAGE_WORKS => ['fa' => 'بازرسی در کارخانه', 'en' => 'At works'],
            self::STAGE_PRE_SHIPMENT => ['fa' => 'پیش از حمل', 'en' => 'Pre-shipment'],
            self::STAGE_ON_ARRIVAL => ['fa' => 'هنگام ورود', 'en' => 'On arrival'],
            default => ['fa' => $stage, 'en' => $stage],
        };
    }
}
