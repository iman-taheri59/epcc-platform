<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * The shell must nest the same way on every screen.
 *
 * Reported by the user: on the newest pages the sidebar overlapped the content
 * and the collapse button was missing. The cause was markup order, not CSS -
 * three views placed the sidebar OUTSIDE the flex container and the topbar as
 * a direct child of it, so the topbar became a third column beside the sidebar
 * instead of a bar above the content.
 *
 * Every existing sidebar test ran against a fixture from ONE page, so a second
 * page with different nesting was invisible to the suite. These assertions are
 * about structure, which is exactly what was wrong.
 */
final class ShellStructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    /** Every shell screen, as a route the user can actually reach. */
    public static function shellPages(): array
    {
        return [
            'portfolio' => ['/'],
            'certificates' => ['/projects/PH-14'],
            'punch' => ['/projects/PH-14/punch'],
            'documents' => ['/projects/PH-14/documents'],
            'transmittals' => ['/projects/PH-14/transmittals'],
            'team' => ['/projects/PH-14/team'],
            'warehouse' => ['/projects/PH-14/materials'],
            'procurement' => ['/projects/PH-14/procurement'],
            'new project' => ['/projects/new'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shellPages')]
    public function test_the_shell_nests_identically_on_every_screen(string $path): void
    {
        $user = $this->userByEmail('karimi@nioc.example');

        $html = $this->actingAs($user)->get($path.'?lang=fa')->assertOk()->getContent();

        $app = strpos($html, '<div class="app">');
        $aside = strpos($html, '<aside class="sb"');
        $wrap = strpos($html, '<div class="wrap">');
        $topbar = strpos($html, '<div class="topbar">');

        $this->assertNotFalse($app, "{$path} has no .app flex container");
        $this->assertNotFalse($aside, "{$path} has no sidebar");
        $this->assertNotFalse($wrap, "{$path} has no .wrap content column");
        $this->assertNotFalse($topbar, "{$path} has no topbar");

        // The sidebar must be INSIDE .app, or it is not part of the flex row
        // and the content slides underneath it.
        $this->assertGreaterThan($app, $aside, "{$path}: sidebar is outside .app");

        // The content column follows the sidebar as its flex sibling.
        $this->assertGreaterThan($aside, $wrap, "{$path}: .wrap does not follow the sidebar");

        // The topbar belongs INSIDE .wrap. As a direct child of .app it becomes
        // a third column and the burger lands beside the sidebar.
        $this->assertGreaterThan($wrap, $topbar, "{$path}: topbar is outside .wrap");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shellPages')]
    public function test_every_screen_can_collapse_its_sidebar(string $path): void
    {
        $user = $this->userByEmail('karimi@nioc.example');

        $html = $this->actingAs($user)->get($path.'?lang=fa')->assertOk()->getContent();

        // The script bails if this id is missing, taking the whole toggle with
        // it - so its presence is the difference between a working control and
        // a button that renders and does nothing.
        $this->assertStringContainsString('id="sb-burger"', $html, "{$path} has no sidebar toggle");
        $this->assertStringContainsString('aria-controls="sb"', $html, "{$path}: toggle is not wired to the sidebar");
    }

    public function test_the_review_screen_nests_correctly_too(): void
    {
        // Not in the provider: it needs a document id, and it was one of the
        // three broken pages.
        $user = $this->userByEmail('karimi@nioc.example');
        $project = \App\Models\Project::where('code', 'PH-14')->firstOrFail();

        $document = app(\App\Support\TenantContext::class)->runAs(
            $user->company_id,
            fn () => \App\Models\Document::where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail(),
            $project->id,
        );

        $html = $this->actingAs($user)
            ->get("/projects/PH-14/documents/{$document->id}/review?lang=fa")
            ->assertOk()->getContent();

        $app = strpos($html, '<div class="app">');
        $aside = strpos($html, '<aside class="sb"');
        $wrap = strpos($html, '<div class="wrap">');
        $topbar = strpos($html, '<div class="topbar">');

        $this->assertGreaterThan($app, $aside, 'review: sidebar is outside .app');
        $this->assertGreaterThan($aside, $wrap, 'review: .wrap does not follow the sidebar');
        $this->assertGreaterThan($wrap, $topbar, 'review: topbar is outside .wrap');
        $this->assertStringContainsString('id="sb-burger"', $html, 'review has no sidebar toggle');
    }

    public function test_the_engine_playground_keeps_the_shell_when_signed_in(): void
    {
        // It is linked FROM the sidebar, so opening it must not strand the
        // user on a page with no navigation.
        $user = $this->userByEmail('karimi@nioc.example');

        $html = $this->actingAs($user)->get('/gate?lang=fa')->assertOk()->getContent();

        $app = strpos($html, '<div class="app">');
        $aside = strpos($html, '<aside class="sb"');
        $wrap = strpos($html, '<div class="wrap">');
        $topbar = strpos($html, '<div class="topbar">');

        $this->assertNotFalse($aside, '/gate lost its sidebar when signed in');
        $this->assertGreaterThan($app, $aside, '/gate: sidebar is outside .app');
        $this->assertGreaterThan($aside, $wrap, '/gate: .wrap does not follow the sidebar');
        $this->assertGreaterThan($wrap, $topbar, '/gate: topbar is outside .wrap');
        $this->assertStringContainsString('id="sb-burger"', $html);
    }

    public function test_the_engine_playground_still_works_without_logging_in(): void
    {
        /*
         * The whole point of this screen is that it needs no database and no
         * tenant - it demos the framework-free rule engine. Adding the shell
         * must not have quietly put it behind auth.
         */
        $html = $this->get('/gate?lang=fa')->assertOk()->getContent();

        $this->assertStringNotContainsString('<aside class="sb"', $html, 'a guest was shown the sidebar');
        $this->assertStringNotContainsString('id="sb-burger"', $html);
        // The actual deliverable is still there.
        $this->assertStringContainsString('standards-engine', $html);
    }

    public function test_no_screen_leaks_an_unrendered_blade_expression(): void
    {
        /*
         * Found by screenshotting /gate: it printed a literal
         * "ips.iran{{ $packVersion }}". The cause was `@{{ ... }}`, which is
         * Blade's ESCAPE syntax for JS templating - it emits the braces
         * verbatim. Intended as a literal "@" separator, it silently disabled
         * the variable. Nothing in the suite looked at rendered output for
         * leftover braces.
         */
        $user = $this->userByEmail('karimi@nioc.example');

        $paths = array_merge(
            array_map(fn (array $r) => $r[0], array_values(self::shellPages())),
            ['/gate'],
        );

        foreach ($paths as $path) {
            $html = $this->actingAs($user)->get($path.'?lang=fa')->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression(
                '/\{\{\s*\$/',
                $html,
                "{$path} rendered an unevaluated Blade expression",
            );
        }
    }

    public function test_the_content_column_cannot_be_widened_by_its_own_content(): void
    {
        /*
         * Found by taking the first screenshot at a phone width.
         *
         * /projects/PH-14/documents?lang=fa at 430px rendered exactly ONE
         * unique colour - the background. Not a slow load and not missing
         * data: the HTML was 66KB with every document in it. The same page in
         * English at the same width was fine, which is what made it findable.
         *
         * Cause: a flex item defaults to min-width:auto, meaning "never shrink
         * below my content's intrinsic width". The documents table is ~1032px
         * intrinsic; the viewport was 485px. So .wrap grew past the viewport
         * instead of overflowing inside it. In LTR that clips harmlessly on
         * the right. In RTL the block is laid out from the right edge, so the
         * overflow pushes the WHOLE document off-screen to the left and the
         * page paints as a blank rectangle.
         *
         * This asserts the CSS contract rather than a screenshot, because the
         * suite has no browser: min-width:0 is the containment, overflow-x is
         * what keeps the hidden columns reachable on a phone. Both are needed
         * - min-width:0 alone would clip the state and revision columns, which
         * are the two fields somebody opens a document register to read.
         */
        $css = file_get_contents(resource_path('views/partials/shell.blade.php'));

        $this->assertMatchesRegularExpression(
            '/\.app\s*>\s*\.wrap\s*\{[^}]*\bmin-width:\s*0\b/',
            $css,
            'the content column can be widened past the viewport by a wide table (blanks every RTL page)',
        );

        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:860px\)\s*\{.*?\.app\s*>\s*\.wrap\s*\{[^}]*\boverflow-x:\s*auto\b/s',
            $css,
            'overflowing table columns are unreachable on a phone',
        );
    }

    public function test_a_wide_table_does_not_push_the_page_off_screen(): void
    {
        /*
         * The behavioural half. The rule above is only meaningful if the pages
         * that carry wide tables actually inherit it, so this checks the shell
         * really is what they render through - a page that stopped using the
         * shell would keep passing the CSS assertion while being broken again.
         */
        $user = $this->userByEmail('karimi@nioc.example');

        foreach (['/projects/PH-14/documents', '/projects/PH-14/transmittals'] as $path) {
            $html = $this->actingAs($user)->get($path.'?lang=fa')->assertOk()->getContent();

            $this->assertStringContainsString('dir="rtl"', $html, "{$path} is not RTL");
            $this->assertStringContainsString('class="app"', $html,
                "{$path} does not render through the shell, so it does not inherit the containment fix");
            $this->assertMatchesRegularExpression(
                '/\.app\s*>\s*\.wrap\s*\{[^}]*\bmin-width:\s*0\b/',
                $html,
                "{$path} ships without the flex containment rule",
            );
        }
    }
}
