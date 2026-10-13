<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class GateScreenTest extends TestCase
{
    public function test_persian_is_the_default_and_page_is_rtl(): void
    {
        $r = $this->get('/gate');
        $r->assertOk();
        $r->assertSee('dir="rtl"', false);
        $r->assertSee('lang="fa"', false);
        $r->assertSee('مجاز به ترخیص');
    }

    public function test_english_switches_direction(): void
    {
        $r = $this->get('/gate?lang=en');
        $r->assertOk();
        $r->assertSee('dir="ltr"', false);
        $r->assertSee('Cleared for release');
    }

    public function test_toggles_can_actually_be_turned_off(): void
    {
        // Regression: an unchecked checkbox is absent from a GET query, so a
        // naive default made the boxes impossible to uncheck.
        $r = $this->get('/gate?submitted=1&consultant=1');
        $r->assertOk();
        $r->assertSee('measurementSheetsAttached');
        $r->assertSee('مسدود');
    }

    public function test_jalali_date_is_rendered_with_persian_digits(): void
    {
        $r = $this->get('/gate');
        $r->assertOk();
        $this->assertMatchesRegularExpression('/[۰-۹]{4}\/[۰-۹]{2}\/[۰-۹]{2}/u', $r->getContent());
    }

    public function test_over_authority_blocks_release(): void
    {
        $r = $this->get('/gate?lang=en&submitted=1&sheets=1&consultant=1&deductions=1&over=1');
        $r->assertOk();
        $r->assertSee('budgetWithinAuthority');
        $r->assertSee('Hard block');
    }
}
