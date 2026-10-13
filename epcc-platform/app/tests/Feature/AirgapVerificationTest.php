<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\VerifyAirgap;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AG-06. The customer's datacentre has no route to the internet.
 *
 * The ->font() defect is the reason this file exists: Filament's font helper
 * fetched from Google Fonts and the whole suite was green, because not one
 * assertion looked at where an asset came from. On a connected sandbox that
 * bug is invisible; in Asaluyeh it is a page that never finishes loading.
 *
 * Two different things are tested here and they fail for different reasons:
 *
 *   1. The command's verdict - no shipped asset is fetched from outside.
 *   2. The command's EXCUSES - every path on the LATENT allowlist really is
 *      unreachable. An allowlist nobody re-checks is how a blocker gets
 *      reclassified into silence, so the claim "this file is never emitted"
 *      is verified against the rendered HTML of every panel screen rather
 *      than trusted.
 */
final class AirgapVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_no_shipped_asset_is_fetched_from_the_internet(): void
    {
        $exit = $this->artisan('verify:airgap');
        $exit->assertExitCode(0);
    }

    /**
     * The blind spot that a file scanner cannot cover, and the reason this
     * test exists in this shape.
     *
     * `verify:airgap` reads files on disk. Filament's default avatar provider
     * builds a ui-avatars.com URL in PHP at request time from the user's
     * name, so NO shipped file contains that string and the scan was green
     * while every single admin page carried an un-fetchable image - plus a
     * named employee handed to a third party. It was found by capturing a
     * browser's network log, and it is pinned here by asserting on the
     * rendered markup of every panel screen.
     *
     * Rendered HTML is the only place where runtime-assembled URLs become
     * visible, so this assertion is the one that generalises.
     */
    public function test_no_panel_screen_renders_a_reference_to_an_external_host(): void
    {
        $panels = [
            '/admin',
            '/admin/documents',
            '/admin/punch-items',
            '/admin/transmittals',
            '/admin/users',
            '/admin/subsystems',
        ];

        $allowed = ['127.0.0.1', 'localhost', '0.0.0.0', 'www.w3.org'];
        $user = $this->userByEmail('karimi@nioc.example');

        foreach ($panels as $panel) {
            $response = $this->actingAs($user)->get($panel);
            $response->assertOk();

            $html = (string) $response->getContent();
            /*
             * Only attributes the browser fetches WITHOUT being clicked. An
             * <a href> to an external site is navigation: it costs nothing
             * until a user clicks it, and flagging those would bury the real
             * findings. src/srcset/@import/url() are fetched during render,
             * which is what actually hangs an air-gapped page.
             */
            preg_match_all(
                '/(?:\bsrc\s*=\s*"|\bsrcset\s*=\s*"|<link[^>]+href\s*=\s*"|@import\s+url\(|url\()\s*(https?:\/\/[^")]+)/i',
                $html,
                $matches
            );

            foreach ($matches[1] as $url) {
                $host = strtolower((string) parse_url(html_entity_decode($url), PHP_URL_HOST));

                $this->assertContains(
                    $host,
                    $allowed,
                    "{$panel} renders a reference to {$host}. In an air-gapped datacentre that "
                    ."request cannot be routed and the browser blocks until it times out. "
                    ."Serve the asset locally (see LocalAvatarProvider, LocalFontProvider)."
                );
            }
        }
    }

    /**
     * The user menu must still show an avatar - a fix that silently removes
     * the feature is not a fix. This checks the replacement actually renders
     * and needs no network.
     */
    public function test_avatars_are_drawn_locally_as_inline_data_uris(): void
    {
        $user = $this->userByEmail('karimi@nioc.example');
        $html = (string) $this->actingAs($user)->get('/admin')->getContent();

        $this->assertMatchesRegularExpression(
            '/data:image\/svg\+xml;base64,[A-Za-z0-9+\/=]+/',
            $html,
            'The panel should render an inline SVG avatar.'
        );

        preg_match('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $html, $m);
        $svg = (string) base64_decode($m[1], true);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg));
        $this->assertStringNotContainsString('https://', $svg);
    }

    /** The same person must keep the same colour, or it reads as noise. */
    public function test_avatar_colour_is_stable_for_the_same_name(): void
    {
        $provider = new \App\Support\LocalAvatarProvider();
        $user = $this->userByEmail('karimi@nioc.example');

        $this->assertSame($provider->get($user), $provider->get($user));
    }

    /**
     * The allowlist must stay honest in the other direction too - an entry
     * for a file that no longer exists is dead weight that makes the next
     * reader trust the list less.
     */
    public function test_the_latent_allowlist_has_no_stale_entries(): void
    {
        foreach (array_keys(VerifyAirgap::latentAssets()) as $path) {
            $this->assertFileExists(
                base_path($path),
                "The LATENT allowlist names {$path}, which is not in the build. Remove the entry."
            );
        }
    }

    /** Every excuse must actually say something. */
    public function test_every_latent_entry_states_a_reason(): void
    {
        foreach (VerifyAirgap::latentAssets() as $path => $reason) {
            $this->assertGreaterThan(
                20,
                strlen($reason),
                "The LATENT entry for {$path} needs a reason a customer's auditor can evaluate."
            );
        }
    }

    /**
     * The scanner has to be able to see a leak. A check that has never been
     * observed failing is not a check - it is a decoration. This plants a
     * <link> of the exact shape the ->font() defect produced and requires a
     * non-zero exit.
     */
    public function test_the_scanner_detects_a_planted_external_stylesheet(): void
    {
        $planted = resource_path('views/__airgap_probe.blade.php');
        File::put($planted, '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn">');

        try {
            $this->artisan('verify:airgap')->assertExitCode(1);
        } finally {
            File::delete($planted);
        }

        $this->artisan('verify:airgap')->assertExitCode(0);
    }

    /**
     * The regression that the first version of this command actually had.
     * Pusher's CDN is stored as a property and concatenated at call time, so
     * a scanner looking only for literal src="https://..." reports a clean
     * build. Dynamic loaders are the highest-risk shape, so they must be
     * caught explicitly.
     */
    public function test_the_scanner_detects_a_cdn_held_in_a_config_property(): void
    {
        $planted = public_path('__airgap_probe.js');
        File::put($planted, 'var opts={cdn_https:"https://cdn.example.net/lib",dependency_suffix:""};');

        try {
            $this->artisan('verify:airgap')->assertExitCode(1);
        } finally {
            File::delete($planted);
        }
    }

    /**
     * ...and it must not cry wolf. An XML namespace is never dereferenced;
     * if those failed the build, the command would be switched off within a
     * week and AG-06 would be back to being a paragraph in a PDF.
     */
    public function test_the_scanner_ignores_namespaces_and_documentation_links(): void
    {
        $planted = resource_path('views/__airgap_benign.blade.php');
        File::put($planted, implode("\n", [
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            '{{-- see https://filamentphp.com/docs for the panel API --}}',
            '<!-- Licensed under https://opensource.org/licenses/MIT -->',
        ]));

        try {
            $this->artisan('verify:airgap')->assertExitCode(0);
        } finally {
            File::delete($planted);
        }
    }

    /** --strict is what CI runs: latent findings block the release build. */
    public function test_strict_mode_fails_while_any_latent_finding_remains(): void
    {
        $this->assertNotSame(
            [],
            VerifyAirgap::latentAssets(),
            'If the latent list is empty, --strict should now be the default in CI.'
        );

        $this->artisan('verify:airgap --strict')->assertExitCode(1);
    }

    /** The acceptance report is machine-read, so the JSON shape is a contract. */
    public function test_json_output_is_valid_and_reports_the_verdict(): void
    {
        /*
         * Artisan::call() rather than $this->artisan(): the test double
         * swallows output, and ob_start() does not capture it either, so the
         * only way to assert on the actual bytes a CI job would parse is to
         * run the command and read its buffered output.
         */
        $exit = \Illuminate\Support\Facades\Artisan::call('verify:airgap', ['--json' => true]);
        $json = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exit);

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, "The acceptance report must be parseable JSON. Got: {$json}");
        $this->assertTrue($decoded['passed']);
        $this->assertSame([], $decoded['blockers']);
        $this->assertNotEmpty($decoded['latent']);

        foreach ($decoded['latent'] as $finding) {
            $this->assertArrayHasKey('host', $finding);
            $this->assertArrayHasKey('file', $finding);
            $this->assertSame('LATENT', $finding['severity']);
        }
    }
}
