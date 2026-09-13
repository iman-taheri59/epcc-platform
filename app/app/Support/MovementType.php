<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The four movements in the material chain the user described.
 *
 *   RECEIPT  ورود به انبار   GRN - goods receipt note
 *   ISSUE    خروج از انبار   MIV - material issue voucher
 *   SITE     ورود به سایت    SDN - site delivery note
 *   RETURN   برگشت به انبار  MRN - material return note
 *
 * The sign lives HERE and not in the data. A stored signed quantity means
 * every reader must know the convention to interpret a row, and sooner or
 * later somebody inserts a negative receipt and the ledger stops adding up.
 * A quantity in this system is always positive; what it means is the type's
 * job.
 *
 * ISSUE and SITE are two steps, not one, because they answer different
 * questions. ISSUE says the warehouse no longer holds it - that is a stock
 * question. SITE says it reached the subsystem and can be installed - that is
 * a completion question, and it is the one the MC gate asks. Material can sit
 * on a laydown area for weeks between the two, and collapsing them would make
 * that invisible exactly when somebody is trying to find it.
 */
final class MovementType
{
    /** Into the warehouse from the vendor. */
    public const RECEIPT = 'RECEIPT';

    /** Out of the warehouse, heading for site. */
    public const ISSUE = 'ISSUE';

    /** Arrived at the subsystem. */
    public const SITE = 'SITE';

    /** Came back unused. */
    public const RETURN = 'RETURN';

    public const ALL = [self::RECEIPT, self::ISSUE, self::SITE, self::RETURN];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }

    /**
     * Effect on WAREHOUSE stock.
     *
     * SITE is deliberately 0: the material left the warehouse at ISSUE, and
     * counting it again on arrival would double-deduct. SITE is a tracking
     * event on material already issued, not a second withdrawal.
     */
    public static function sign(string $type): int
    {
        return match ($type) {
            self::RECEIPT, self::RETURN => 1,
            self::ISSUE => -1,
            self::SITE => 0,
            default => 0,
        };
    }

    /** Does this movement need a subsystem to make sense? */
    public static function requiresSubsystem(string $type): bool
    {
        return $type === self::SITE;
    }

    /** Does it reduce what the warehouse can still give out? */
    public static function consumesStock(string $type): bool
    {
        return self::sign($type) < 0;
    }

    /** @return array{fa:string,en:string} */
    public static function label(string $type): array
    {
        return match ($type) {
            self::RECEIPT => ['fa' => 'ورود به انبار', 'en' => 'Goods receipt'],
            self::ISSUE => ['fa' => 'خروج از انبار', 'en' => 'Material issue'],
            self::SITE => ['fa' => 'ورود به سایت', 'en' => 'Site delivery'],
            self::RETURN => ['fa' => 'برگشت به انبار', 'en' => 'Return to store'],
            default => ['fa' => $type, 'en' => $type],
        };
    }

    public static function name(string $type, string $lang = 'fa'): string
    {
        return self::label($type)[$lang] ?? $type;
    }

    /** Document prefix, matching the register conventions elsewhere. */
    public static function prefix(string $type): string
    {
        return match ($type) {
            self::RECEIPT => 'GRN',
            self::ISSUE => 'MIV',
            self::SITE => 'SDN',
            self::RETURN => 'MRN',
            default => 'MOV',
        };
    }
}
