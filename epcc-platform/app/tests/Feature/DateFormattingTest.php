<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\JalaliDate;
use DateTimeImmutable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Date formatting, and the rule that there is exactly one implementation of it.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * The admin panel shipped `سپتامبر 12, 2026` on an otherwise fully Persian
 * page, at HTTP 200, with every test green. The cause was not the panel: it
 * was that Jalali formatting only ever existed as a private closure inside
 * each Blade view. Each screen carried its own copy, they matched only by
 * luck, and a surface written months later could reuse none of them - so it
 * reached for Filament's `->dateTime()`, which renders Gregorian.
 *
 * On a transmittal log a date is contractual: it decides whether a reply was
 * inside the 14-day window. So two of these tests assert on the SHAPE OF THE
 * CODEBASE rather than on output - a re-introduced closure or a new
 * `->dateTime()` fails here immediately, instead of surviving until someone
 * happens to look at a screenshot again.
 */
final class DateFormattingTest extends TestCase
{
    public function test_no_hand_rolled_jalali_conversion_is_left_in_any_view(): void
    {
        $offenders = [];

        foreach ($this->filesUnder(resource_path('views'), '.blade.php') as $path) {
            if (str_contains((string) file_get_contents($path), 'Morilog')) {
                $offenders[] = str_replace(resource_path('views').'/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            'Views must call App\Support\JalaliDate, not Morilog directly.');
    }

    public function test_no_filament_column_formats_a_date_with_the_gregorian_helper(): void
    {
        $offenders = [];

        foreach ($this->filesUnder(app_path('Filament'), '.php') as $path) {
            foreach (file($path) as $n => $line) {
                $trimmed = ltrim($line);

                // A doc-comment explaining why ->dateTime() is banned is not a
                // violation. (Learned the hard way: a previous grep-style
                // assertion failed against the comment describing the rule.)
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                if (preg_match('/->date(Time)?\(/', $line) === 1) {
                    $offenders[] = basename($path).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders,
            'Filament ->dateTime() renders Gregorian. Use JalaliDate::format($state, app()->getLocale()).');
    }

    public function test_a_date_is_formatted_in_jalali_with_persian_digits(): void
    {
        $d = new DateTimeImmutable('2026-09-12 08:30:00');

        $this->assertSame('۱۴۰۵/۰۶/۲۱', JalaliDate::format($d, 'fa'));
        $this->assertSame('2026-09-12', JalaliDate::format($d, 'en'));
        $this->assertSame('۱۴۰۵/۰۶/۲۱ ۰۸:۳۰', JalaliDate::formatWithTime($d, 'fa'));
        $this->assertSame('2026-09-12 08:30', JalaliDate::formatWithTime($d, 'en'));
    }

    public function test_a_missing_date_renders_an_em_dash_not_an_empty_cell(): void
    {
        // A DRAFT transmittal has no issue date. A blank cell reads as a
        // rendering bug; the em dash says "deliberately not set yet".
        $this->assertSame('—', JalaliDate::format(null, 'fa'));
        $this->assertSame('—', JalaliDate::format(null, 'en'));
        $this->assertSame('—', JalaliDate::formatWithTime(null, 'fa'));
    }

    public function test_digits_are_converted_only_for_persian(): void
    {
        $this->assertSame('۱,۲۵۰', JalaliDate::digits('1,250', 'fa'));
        $this->assertSame('1,250', JalaliDate::digits('1,250', 'en'));
    }

    /** @return list<string> */
    private function filesUnder(string $dir, string $suffix): array
    {
        $out = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }
}
