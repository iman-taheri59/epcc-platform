<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A machine-readable authorization refusal (ARC §5).
 *
 * Bilingual by construction: the SRS requires every user-visible string in
 * both languages (FR-I18N-01), and "access denied" is precisely the message a
 * frustrated user will screenshot and send to support.
 */
final class Denial
{
    private const MESSAGES = [
        'ACCOUNT_DISABLED' => [
            'en' => 'This account has been disabled.',
            'fa' => 'این حساب کاربری غیرفعال شده است.',
        ],
        'NO_PLAN' => [
            'en' => 'Your company has no active subscription.',
            'fa' => 'شرکت شما اشتراک فعالی ندارد.',
        ],
        'ENTITLEMENT_MISSING' => [
            'en' => 'Your plan does not include this module.',
            'fa' => 'این ماژول در طرح اشتراک شما گنجانده نشده است.',
        ],
        'PACK_NOT_ENTITLED' => [
            'en' => 'Your plan does not include this project\'s standards pack.',
            'fa' => 'بستهٔ استاندارد این پروژه در طرح اشتراک شما نیست.',
        ],
        'NOT_PROJECT_MEMBER' => [
            'en' => 'You are not a member of this project.',
            'fa' => 'شما عضو این پروژه نیستید.',
        ],
        'CAPABILITY_NOT_GRANTED' => [
            'en' => 'Your role on this project does not permit this action.',
            'fa' => 'نقش شما در این پروژه اجازهٔ این عملیات را نمی‌دهد.',
        ],
        'SCOPE_EXCLUDED' => [
            'en' => 'This discipline is outside your assigned scope.',
            'fa' => 'این رشته خارج از دامنهٔ تعیین‌شده برای شماست.',
        ],
        'AUTHORITY_EXCEEDED' => [
            'en' => 'The amount exceeds your delegated authority.',
            'fa' => 'مبلغ از حد اختیارات تفویض‌شده به شما بیشتر است.',
        ],
        'SOD_CONFLICT' => [
            'en' => 'Segregation of duties: you already acted on this item in a conflicting role.',
            'fa' => 'تفکیک وظایف: شما پیش‌تر در نقشی متعارض روی این مورد اقدام کرده‌اید.',
        ],
    ];

    public function __construct(
        public readonly string $gate,
        public readonly string $reason,
        public readonly ?string $capability = null,
    ) {
    }

    public function message(string $lang): string
    {
        $m = self::MESSAGES[$this->reason] ?? null;

        if ($m === null) {
            return $lang === 'fa' ? 'دسترسی مجاز نیست.' : 'Access denied.';
        }

        return $m[$lang] ?? $m['en'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'gate' => $this->gate,
            'reason' => $this->reason,
            'capability' => $this->capability,
            'message_en' => $this->message('en'),
            'message_fa' => $this->message('fa'),
        ];
    }
}
