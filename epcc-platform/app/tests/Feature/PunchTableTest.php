<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PunchCategory;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * Two defects reported by the user on the QA/QC register.
 *
 * 1. The header row was offset by one column against the body, so "Subsystem"
 *    sat above the category data. Cause: `.mono` sets `display:inline-block`
 *    to hold an LTR run inside RTL text, and it was applied to <td> elements -
 *    where it REPLACES `display:table-cell` and drops the cell out of the
 *    column layout entirely.
 * 2. The punch category was translated to الف/ب/ج in Persian. It is a
 *    contractual term that must read A/B/C in both languages.
 */
final class PunchTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function user(): \App\Models\User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    public function test_a_table_cell_never_loses_its_table_display(): void
    {
        /*
         * The structural guard. `display:inline-block` on a <td> silently
         * shifts every following column by one - the table still has the right
         * number of cells, so nothing but layout reveals it.
         */
        $css = file_get_contents(resource_path('views/partials/shell.blade.php'));

        $this->assertMatchesRegularExpression(
            '/td\.mono[^{]*\{[^}]*display\s*:\s*table-cell/',
            $css,
            'td.mono does not restore display:table-cell, so the register will misalign again',
        );
    }

    public function test_the_category_reads_as_a_latin_letter_in_both_languages(): void
    {
        foreach (['A', 'B', 'C'] as $letter) {
            $this->assertSame($letter, PunchCategory::shortLabel($letter, 'fa'), "fa short label for {$letter}");
            $this->assertSame($letter, PunchCategory::shortLabel($letter, 'en'), "en short label for {$letter}");

            // The long label keeps translated prose but must START with the letter.
            $fa = PunchCategory::label($letter)['fa'];
            $this->assertStringStartsWith($letter, $fa, "fa long label for {$letter} does not lead with the letter");
        }
    }

    public function test_no_persian_letter_is_used_as_a_category(): void
    {
        // The specific regression: الف / ب / ج as category identifiers.
        foreach (['A', 'B', 'C'] as $letter) {
            foreach (['fa', 'en'] as $lang) {
                $label = PunchCategory::label($letter)[$lang];

                foreach (['الف', 'ردهٔ ب', 'ردهٔ ج'] as $persianLetter) {
                    $this->assertStringNotContainsString(
                        $persianLetter,
                        $label,
                        "{$lang} label for {$letter} still uses a Persian category letter",
                    );
                }
            }
        }
    }

    public function test_the_register_renders_latin_categories_in_persian(): void
    {
        $html = $this->actingAs($this->user())
            ->get('/projects/PH-14/punch?lang=fa')
            ->assertOk()
            ->getContent();

        // The seeded register has A, B and C items.
        $this->assertStringContainsString('<span class="cat A">A</span>', $html);
        $this->assertStringContainsString('<span class="cat B">B</span>', $html);
        $this->assertStringContainsString('<span class="cat C">C</span>', $html);

        $this->assertStringNotContainsString('>الف<', $html, 'the register still prints a Persian category letter');
    }

    public function test_the_register_header_and_body_have_the_same_column_count(): void
    {
        /*
         * A count check cannot see the CSS bug above, but it catches the other
         * way a register drifts: someone adds a <th> and forgets the <td>.
         */
        $html = $this->actingAs($this->user())
            ->get('/projects/PH-14/punch?lang=fa')
            ->assertOk()
            ->getContent();

        $table = substr($html, strpos($html, '<table data-tt>'));
        $table = substr($table, 0, strpos($table, '</table>'));

        $thead = substr($table, strpos($table, '<thead>'), strpos($table, '</thead>'));
        $headCount = preg_match_all('/<th[\s>]/', $thead);

        $body = substr($table, strpos($table, '<tbody>'));
        $firstRow = substr($body, strpos($body, '<tr>'), strpos($body, '</tr>'));
        $cellCount = preg_match_all('/<td[\s>]/', $firstRow);

        $this->assertSame($headCount, $cellCount, 'the register header and its rows disagree on column count');
        $this->assertSame(7, $headCount);
    }

    public function test_the_blocking_message_names_the_category_in_latin(): void
    {
        // The gate message is what an owner reads before granting an exception.
        $html = $this->actingAs($this->user())
            ->get('/projects/PH-14/punch?lang=fa')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ردهٔ A', $html);
        $this->assertStringNotContainsString('ردهٔ الف', $html);
    }
}
