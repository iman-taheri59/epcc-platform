<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LoginPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_default_login_does_not_publish_or_query_the_user_directory(): void
    {
        config(['epcc.demo_login' => false]);
        DB::enableQueryLog();
        $this->get('/login?lang=en')->assertOk()
            ->assertDontSee('karimi@nioc.example')
            ->assertDontSee('Demo accounts')
            ->assertDontSee('function fill(');
        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertSame([], array_values(array_filter($queries,
            fn ($sql) => (bool) preg_match('/from\s+"?users"?/i', $sql))));
    }

    public function test_demo_accounts_remain_available_with_explicit_development_opt_in(): void
    {
        config(['epcc.demo_login' => true]);
        $this->get('/login?lang=en')->assertOk()->assertSee('karimi@nioc.example')->assertSee('Demo accounts');
    }

    public function test_production_never_lists_demo_accounts_even_with_misconfigured_opt_in(): void
    {
        config(['epcc.demo_login' => true]);
        $this->app->instance('env', 'production');
        $this->get('/login?lang=en')->assertOk()
            ->assertDontSee('karimi@nioc.example')->assertDontSee('Demo accounts');
    }
}
