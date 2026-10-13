<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\JalaliDate;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

final class MaterialsPresentationTest extends TestCase
{
    public function test_materials_headers_use_the_shared_localized_date_formatter(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->actingAs($this->userByEmail('karimi@nioc.example'));
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(12, 0));

        foreach (['fa', 'en'] as $lang) {
            foreach (['materials', 'procurement'] as $screen) {
                $expected = JalaliDate::format(now(), $lang);
                $this->get('/projects/PH-14/'.$screen.'?lang='.$lang)
                    ->assertOk()->assertViewHas('jalaliDate', $expected)
                    ->assertSee($expected);
            }
        }
    }
}
