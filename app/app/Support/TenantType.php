<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The nine company types from SRS §3.1.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * These codes were previously loose strings in five files, and one of them was
 * wrong for months without anything failing: `MC` was labelled "Mechanical
 * Completion body" in the seeder, two Blade views, the SRS role table and the
 * coverage map. It is not. See the note on MC below.
 *
 * Nothing caught it because a tenant type only has to be a matching string -
 * the label is decoration to the code and load-bearing to the user. So the
 * labels live here, once, next to the authority that says which codes exist.
 *
 * A CHECK constraint on companies.tenant_type (migration
 * 2026_09_11_140000) pins the same list at the database, so an importer or a
 * seeder cannot invent a tenth type that the role rules would then silently
 * refuse to recognise.
 */
final class TenantType
{
    /** Owner / Client / Employer - کارفرما. */
    public const OWN = 'OWN';

    /** EPC / EPCC main contractor - پیمانکار اصلی. */
    public const EPC = 'EPC';

    /** Consultant / PMC / Engineer of record - مشاور. */
    public const PMC = 'PMC';

    /** Subcontractor - پیمانکار جزء. */
    public const SUB = 'SUB';

    /** Vendor / Supplier / Fabricator - فروشنده / سازنده. */
    public const VEN = 'VEN';

    /**
     * MANAGEMENT CONTRACTOR - مدیریت پیمان.
     *
     * A firm the EMPLOYER engages to administer the contract between the
     * employer and the contractor: it manages the contract on the employer's
     * behalf, supervises execution, checks progress and quality, verifies what
     * the contractor claims, and signs off milestones.
     *
     * It is NOT "Mechanical Completion". That is a MILESTONE (the point where
     * a subsystem is built and ready for pre-commissioning), it is not a kind
     * of company, and its abbreviation collides with this one. In this codebase
     * the milestone lives in McCertificate / mc_certificates /
     * PunchCategory::blocksMechanicalCompletion(); this constant is a company.
     *
     * The distinction matters commercially: مدیریت پیمان is a standard Iranian
     * contracting arrangement with its own fee structure, and an employer
     * buying the platform expects to see its own MC firm as a participant type.
     */
    public const MC = 'MC';

    /** Third Party Inspection - بازرسی شخص ثالث. */
    public const TPI = 'TPI';

    /** Certification / Notified Body / Authority - نهاد صدور گواهی. */
    public const CB = 'CB';

    /** Internal - the platform operator. No project data by default. */
    public const INT = 'INT';

    /** @var list<string> */
    public const ALL = [
        self::OWN, self::EPC, self::PMC, self::SUB,
        self::VEN, self::MC, self::TPI, self::CB, self::INT,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /**
     * @return array{fa:string,en:string}
     */
    public static function label(string $type): array
    {
        return match ($type) {
            self::OWN => ['fa' => 'کارفرما', 'en' => 'Owner / Employer'],
            self::EPC => ['fa' => 'پیمانکار اصلی', 'en' => 'EPC contractor'],
            self::PMC => ['fa' => 'مشاور / ناظر', 'en' => 'Consultant / PMC'],
            self::SUB => ['fa' => 'پیمانکار جزء', 'en' => 'Subcontractor'],
            self::VEN => ['fa' => 'فروشنده / سازنده', 'en' => 'Vendor / supplier'],
            self::MC => ['fa' => 'مدیریت پیمان', 'en' => 'Management contractor'],
            self::TPI => ['fa' => 'بازرسی شخص ثالث', 'en' => 'Third party inspection'],
            self::CB => ['fa' => 'نهاد صدور گواهی', 'en' => 'Certification body'],
            self::INT => ['fa' => 'داخلی (اپراتور سامانه)', 'en' => 'Internal (platform operator)'],
            default => ['fa' => $type, 'en' => $type],
        };
    }

    public static function name(string $type, string $lang = 'fa'): string
    {
        return self::label($type)[$lang] ?? $type;
    }
}
