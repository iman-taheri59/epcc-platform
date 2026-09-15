<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CdeState;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * Filter + sort on data tables (SRS FR-UX-04).
 *
 * The BEHAVIOUR lives in tests/Browser/table-tools.test.cjs, which drives the
 * real script in jsdom. These are the structural guards: they make sure every
 * table is actually wired up, and that the columns whose display text sorts
 * WRONGLY carry an explicit sort key.
 *
 * A new screen with a table is the obvious way this regresses - which is why
 * the last test walks every view rather than naming the three that exist now.
 */
final class TableToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    private function page(string $email, string $url): string
    {
        return $this->actingAs($this->user($email))
            ->get($url, ['Host' => 'preview.arena.site'])
            ->assertOk()
            ->getContent();
    }

    public function test_the_document_register_table_is_filterable_and_sortable(): void
    {
        $html = $this->page('rezaei@pars.example', '/projects/PH-14/documents?lang=en');

        $this->assertStringContainsString('<table data-tt>', $html);
        $this->assertStringContainsString('tt-bar', $html, 'the table-tools partial was not included');
        // Sortable on the columns a user actually orders by.
        $this->assertMatchesRegularExpression('/<th data-sort="text">\s*Document no\./', $html);
        // Faceted on state and originator: "what is still in review?" and
        // "what has that contractor issued?" are the two standing questions.
        $this->assertStringContainsString('data-facet>', $html);
    }

    public function test_the_certificate_table_is_filterable_and_sortable(): void
    {
        $html = $this->page('karimi@nioc.example', '/projects/PH-14?lang=en');

        $this->assertStringContainsString('<table data-tt>', $html);
        $this->assertStringContainsString('tt-bar', $html);
        $this->assertStringContainsString('data-sort="num"', $html, 'the money column must sort numerically');
    }

    public function test_the_team_table_is_filterable_and_sortable(): void
    {
        $html = $this->page('karimi@nioc.example', '/projects/PH-14/team?lang=en');

        $this->assertStringContainsString('<table data-tt>', $html);
        $this->assertStringContainsString('tt-bar', $html);
    }

    public function test_the_cde_column_sorts_by_lifecycle_not_by_label(): void
    {
        // Sorting the rendered label is wrong in BOTH languages: alphabetically
        // "Archived" precedes "WIP", and the Persian labels collate differently
        // again. The register is read as a pipeline and must sort as one.
        $html = $this->page('rezaei@pars.example', '/projects/PH-14/documents?lang=fa');

        foreach (CdeState::all() as $state) {
            $ordinal = CdeState::ordinalOf($state);
            $this->assertStringContainsString(
                'data-sortkey="'.$ordinal.'"',
                $html,
                "no lifecycle sort key emitted for {$state}",
            );
        }
    }

    public function test_lifecycle_ordinals_follow_the_state_machine(): void
    {
        $this->assertSame(0, CdeState::ordinalOf(CdeState::WIP));
        $this->assertSame(1, CdeState::ordinalOf(CdeState::SHARED));
        $this->assertSame(2, CdeState::ordinalOf(CdeState::PUBLISHED));
        $this->assertSame(3, CdeState::ordinalOf(CdeState::ARCHIVED));
        // A sort must never be the thing that takes a page down.
        $this->assertSame(99, CdeState::ordinalOf('NOT-A-STATE'));
    }

    public function test_the_money_column_sorts_on_the_raw_value(): void
    {
        // The cell renders "۴۳٬۲۳۴٬۵۰۰٬۰۰۰"; sorting that as text is nonsense.
        $html = $this->page('karimi@nioc.example', '/projects/PH-14?lang=fa');

        $this->assertMatchesRegularExpression(
            '/<td class="amt" data-sortkey="[0-9.]+"/',
            $html,
            'the amount cell must carry a raw numeric sort key',
        );
    }

    public function test_the_gate_column_facets_on_the_verdict_not_the_reasons(): void
    {
        // The cell also lists every failure reason. Faceting on raw text would
        // give each blocked row its own dropdown entry - a filter with one row
        // behind every option is not a filter.
        $html = $this->page('karimi@nioc.example', '/projects/PH-14?lang=en');

        $this->assertMatchesRegularExpression(
            '/data-sortkey="(cleared|blocked|ungated)" data-facet-value="[^"]+"/',
            $html,
            'the gate cell must declare an explicit facet value',
        );
    }

    public function test_the_toolbar_is_built_by_script_so_a_plain_table_still_works(): void
    {
        // Progressive enhancement: the server must NOT ship a search box that
        // would be dead if the script failed. The markup contains the styles
        // and the script, never a pre-rendered input.
        $html = $this->page('rezaei@pars.example', '/projects/PH-14/documents?lang=en');

        $this->assertStringNotContainsString('class="tt-bar"><input', $html);
        $this->assertStringContainsString('.tt-bar {', $html, 'the toolbar styles must ship');
    }

    public function test_the_script_waits_for_the_dom_before_wiring_tables(): void
    {
        // The partial is included in <head>, long before the tables exist.
        // Without this guard it finds nothing and fails silently - exactly the
        // bug that made the sidebar burger dead (HANDOFF §5).
        $html = $this->page('rezaei@pars.example', '/projects/PH-14/documents?lang=en');

        $this->assertStringContainsString("document.readyState === 'loading'", $html);
        $this->assertStringContainsString("addEventListener('DOMContentLoaded', init)", $html);
    }

    public function test_every_view_with_a_table_opts_into_the_tools(): void
    {
        // The standing rule, enforced structurally: a future screen that adds a
        // <table> without data-tt fails here rather than shipping a table the
        // user cannot filter.
        $root = resource_path('views');
        $missing = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            /*
             * Print views are exempt. A PDF has no JavaScript and no user to
             * click a dropdown, so a filter toolbar there would be dead markup
             * that also prints. The rule this guard enforces is about SCREENS.
             */
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/views/print/')) {
                continue;
            }

            $body = file_get_contents($file->getPathname());

            /*
             * An editor grid is not a data table. piping/config renders the
             * number-format tokens as rows of <input>s whose ORDER IS THE
             * DATA - token 1 then token 2 is what the number looks like - so
             * offering to sort them would corrupt the thing being edited, and
             * filtering would hide rows that are about to be submitted as a
             * set. Opting out is declared in the view itself so the exemption
             * is visible where someone might otherwise add data-tt.
             */
            if (str_contains($body, 'data-tt-exempt')) {
                continue;
            }

            // Only tables with a <thead> are data tables; a layout table would
            // not have one. None exist today, but the distinction keeps this
            // guard from becoming a nuisance that gets deleted.
            if (! str_contains($body, '<table') || ! str_contains($body, '<thead')) {
                continue;
            }

            // Match the ATTRIBUTE on the tag, not the string anywhere in the
            // file. Searching for 'data-tt' loosely made this guard inert: the
            // explanatory comment above each table contains that text, so a
            // table that lost its attribute still "passed".
            if (! preg_match('/<table[^>]*\sdata-tt[\s>]/', $body)) {
                $missing[] = str_replace($root.'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these views have a data table with no filter/sort: \n  ".implode("\n  ", $missing),
        );
    }
}
