<?php

declare(strict_types=1);

/**
 * Persian strings for the Filament admin panel.
 *
 * The hand-written screens carry their own bilingual arrays inline, because
 * each one needs only a handful of strings and keeping them next to the markup
 * made them easy to review. Filament is different: its labels are set in PHP
 * far from any view, and the same key is reused across table, form and
 * breadcrumb. So the panel uses Laravel's translator, and these files are the
 * source.
 *
 * Persian is the DEFAULT locale (launch market is Iran), not a translation of
 * an English original.
 */
return [
    'doc' => [
        'label' => 'مدرک',
        'plural' => 'مدارک',
        'number' => 'شمارهٔ مدرک',
        'title' => 'عنوان',
        'title_fa' => 'عنوان فارسی',
        'title_en' => 'عنوان انگلیسی',
        'discipline' => 'رشته',
        'type' => 'نوع مدرک',
        'state' => 'وضعیت',
        'revision' => 'بازنگری',
        'owner' => 'شرکت مالک',
        'created' => 'تاریخ ایجاد',
        'project' => 'پروژه',
        'identity' => 'شناسه مدرک',
        'identity_hint' => 'این فیلدها توسط سامانهٔ شماره‌گذاری تعیین می‌شوند و قابل ویرایش نیستند.',
        'titles' => 'عنوان‌ها',
    ],

    'punch' => [
        'label' => 'نقص',
        'plural' => 'فهرست نواقص',
        'number' => 'شمارهٔ نقص',
        'subsystem' => 'زیرسیستم',
        'category' => 'رده',
        'state' => 'وضعیت',
        'description' => 'شرح',
        'location' => 'موقعیت',
        'raiser' => 'ثبت‌کننده',
        'responsible' => 'مسئول رفع',
        'raised_at' => 'تاریخ ثبت',
        'blocks_mc' => 'مانع تکمیل مکانیکی',
    ],

    'tr' => [
        'label' => 'نامهٔ ارسال',
        'plural' => 'نامه‌های ارسال',
        'number' => 'شمارهٔ نامه',
        'kind' => 'جریان',
        'kind_contractor' => 'پیمانکار',
        'kind_vendor' => 'وندور',
        'subject' => 'موضوع',
        'purpose' => 'هدف ارسال',
        'status' => 'وضعیت',
        'status_draft' => 'پیش‌نویس',
        'status_issued' => 'صادرشده',
        'vendor' => 'وندور',
        'vendor_reference' => 'شمارهٔ نامهٔ وندور',
        'recorder' => 'ثبت‌کننده',
        'issued_at' => 'تاریخ صدور',
        'due_at' => 'مهلت پاسخ',
        'items' => 'تعداد مدارک',
        'overdue' => 'معوق',
    ],

    'usr' => [
        'label' => 'کاربر',
        'plural' => 'کاربران',
        'name' => 'نام',
        'email' => 'رایانامه',
        'company' => 'شرکت',
        'tenant_type' => 'نوع شرکت',
        'locale' => 'زبان',
        'active' => 'فعال',
        'status' => 'وضعیت',
        'is_active' => 'حساب فعال',
        'inactive' => 'غیرفعال',
        'projects' => 'پروژه‌ها',
        'roles' => 'نقش‌ها',
        'no_roles' => 'بدون نقش',
        'created' => 'تاریخ ایجاد',
        'colleague' => 'همکار',
        'counterparty' => 'طرف قرارداد',
        'relation' => 'نسبت',
    ],

    'nav_people' => 'سازمان',

    'ss' => [
        'facility' => 'تأسیسات',
        'area' => 'ناحیه',
        'label' => 'زیرسیستم',
        'plural' => 'زیرسیستم‌ها',
        'code' => 'کد زیرسیستم',
        'name' => 'عنوان',
        'discipline' => 'دیسیپلین',
        'project' => 'پروژه',
        'company' => 'پیمانکار',
        'punch' => 'نواقص',
        'blocking' => 'اقلام بازدارنده',
        'closure' => 'درصد رفع',
        'gate' => 'دروازهٔ تکمیل مکانیکی',
        'blocked' => 'مسدود',
        'clear' => 'آزاد',
        'cert' => 'گواهی',
        'no_cert' => 'صادر نشده',
        'not_walked' => 'بازدید نشده',
        'created' => 'تاریخ ایجاد',
        'only_blocked' => 'فقط مسدودها',
    ],

    'nav_qaqc' => 'کیفیت',

    'nav' => [
        'engineering' => 'مهندسی',
    ],

    'project' => [
        'state' => [
            'active' => 'فعال',
            'archived' => 'بایگانی‌شده',
        ],
        'archived_badge' => 'بایگانی‌شده - فقط خواندنی',
        'archive' => 'بایگانی پروژه',
        'reopen' => 'بازگشایی پروژه',
        'archive_reason' => 'دلیل بایگانی',
        'archived_on' => 'بایگانی‌شده در :date توسط :user',
        'read_only' => 'این پروژه بایگانی شده و فقط خواندنی است. برای تغییر، آن را بازگشایی کنید.',
        'already_archived' => 'این پروژه از قبل بایگانی شده است.',
        'not_archived' => 'این پروژه بایگانی نشده است.',
        'bad_transition' => 'این تغییر وضعیت پروژه مجاز نیست.',
        'archive_owner_only' => 'فقط شرکت کارفرما می‌تواند پروژه را بایگانی یا بازگشایی کند.',
        'quota_reached' => 'طرح اشتراک شما :max پروژهٔ فعال را مجاز می‌داند. ابتدا یک پروژهٔ دیگر را بایگانی کنید.',
        'archive_confirm' => 'با بایگانی، کل پروژه برای همهٔ شرکت‌ها فقط خواندنی می‌شود. هیچ داده‌ای حذف نمی‌شود و بعداً می‌توانید بازگشایی کنید.',
        'archived_count' => ':count بایگانی‌شده',
        'show_archived' => 'نمایش پروژه‌های بایگانی‌شده',
    ],

    'panel' => [
        'brand' => 'سامانهٔ EPCC',
    ],
];
