<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/** Fresh authenticated HTML for JS harnesses, never a stale /tmp screenshot. */
final class BrowserFixtureTest extends TestCase
{
    public function test_document_register_renders_both_languages_and_can_supply_browser_fixtures(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->actingAs($this->userByEmail('rezaei@pars.example'));

        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $direction) {
            $response = $this->get('/projects/PH-14/documents?lang='.$lang);
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $response->assertSee('PH-14', false);
            $directory = getenv('EPCC_BROWSER_FIXTURE_DIR');
            if ($directory !== false && $directory !== '') {
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                $this->assertNotFalse(file_put_contents($directory.'/documents-'.$lang.'.html', $response->getContent()));
            }
        }
    }

    public function test_test_storage_is_not_the_application_document_store(): void
    {
        $this->assertNotSame(base_path('storage'), storage_path());
        $this->assertStringStartsWith(sys_get_temp_dir().'/epcc-tests-', storage_path());
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame(storage_path('config.php'), $this->app->getCachedConfigPath());
    }
}
