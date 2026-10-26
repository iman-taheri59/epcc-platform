<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Support\Navigation;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * The sidebar must mirror what the company bought - never more, never less.
 *
 * A navigation menu is an authorization surface. If it offers a screen the
 * pipeline (ARC §5) then refuses, the user cannot tell a bug from a permission
 * problem from a billing problem, and support pays for it forever. So the menu
 * is derived from the same two gates: ENTITLEMENT (plan modules) and RBAC
 * (capabilities).
 *
 * Three states, and conflating any two of them is a real defect:
 *   available - entitled and built
 *   planned   - entitled, not built yet ("Soon")
 *   locked    - not in the plan ("Upgrade") - the commercial upsell surface
 */
final class SidebarNavigationTest extends TestCase
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

    /** @return array<string,array<string,mixed>> keyed by item key */
    private function items(User $user, string $lang = 'en', ?string $projectCode = null): array
    {
        $project = $projectCode === null ? null : Project::where('code', $projectCode)->first();

        // Navigation reads the user's memberships to decide what to show, and
        // project_memberships now carries RLS. In the application this always
        // runs inside BindTenant; calling the builder directly from a test
        // does not, so the memberships would be invisible and every
        // project-scoped item would silently disappear. Bind the same tenant
        // the middleware would have bound.
        $nav = app(TenantContext::class)->runAs(
            $user->company_id,
            fn () => app(Navigation::class)->build(
                $user,
                $lang,
                $projectCode === null ? '/' : "/projects/{$projectCode}",
                $project,
            ),
        );

        $out = [];

        foreach ($nav as $section) {
            foreach ($section['items'] as $item) {
                $out[$item['key']] = $item;
            }
        }

        return $out;
    }

    public function test_every_shell_screen_renders_the_sidebar(): void
    {
        // The reported bug: logging in landed on a page with no sidebar at
        // all, so there was no way to reach anything else.
        $karimi = $this->user('karimi@nioc.example');

        foreach (['/', '/projects/new', '/projects/PH-14', '/projects/PH-14/team'] as $path) {
            $this->actingAs($karimi)->get($path.'?lang=en')
                ->assertOk()
                ->assertSee('class="sb"', false)
                ->assertSee('Portfolio');
        }
    }

    public function test_a_module_outside_the_plan_is_locked_not_hidden(): void
    {
        // Arvand is on Starter: core + doc only. The cost module (certificates)
        // is not bought, so it must appear as an upgrade prompt - hiding it
        // would throw away the only in-product upsell surface (SRS BR-02).
        $items = $this->items($this->user('hosseini@arvand.example'), 'en', 'KISH-1');

        $this->assertSame('locked', $items['project.certificates']['state']);
        $this->assertSame('Upgrade', $items['project.certificates']['badge']);
        $this->assertNull($items['project.certificates']['url'], 'a locked item must not be clickable');
    }

    public function test_an_entitled_but_unbuilt_module_says_soon_not_upgrade(): void
    {
        // Telling a customer to upgrade for something they already pay for is
        // a billing complaint waiting to happen; it is simply not built yet
        // (COV-EPCC-001). NIOC is on `special` ('*'), so every module is
        // entitled - which makes it the right fixture for "entitled but
        // unbuilt". Update this as modules land; `doc` graduated when the
        // document register shipped.
        $items = $this->items($this->user('karimi@nioc.example'), 'en', 'PH-14');

        $this->assertSame('planned', $items['project.awp']['state']);
        $this->assertSame('Soon', $items['project.awp']['badge']);
        $this->assertNull($items['project.awp']['url']);
    }

    public function test_a_module_that_has_shipped_is_no_longer_planned(): void
    {
        // The document register is built and sold in Starter, so it must be a
        // real link for a Starter customer - not a "Soon" placeholder.
        $items = $this->items($this->user('hosseini@arvand.example'), 'en', 'KISH-1');

        $this->assertSame('available', $items['project.documents']['state']);
        $this->assertNotNull($items['project.documents']['url']);
        $this->assertNull($items['project.documents']['badge']);
    }

    public function test_a_special_plan_unlocks_every_module(): void
    {
        // NIOC is on `special` ('*'), so nothing may be marked locked.
        $items = $this->items($this->user('karimi@nioc.example'), 'en', 'PH-14');

        foreach ($items as $key => $item) {
            $this->assertNotSame('locked', $item['state'], "{$key} must not be locked on the special plan");
        }

        $this->assertSame('available', $items['project.certificates']['state']);
    }

    public function test_new_project_appears_only_for_an_employer(): void
    {
        // Same rule the portfolio button uses; ProjectSetupController re-checks
        // it on both verbs, so this is a convenience and never a control.
        $this->assertArrayHasKey('projects.create', $this->items($this->user('karimi@nioc.example')));
        $this->assertArrayNotHasKey('projects.create', $this->items($this->user('rezaei@pars.example')));
    }

    public function test_project_items_appear_only_with_a_project_in_scope(): void
    {
        $portfolio = $this->items($this->user('karimi@nioc.example'));

        $this->assertArrayNotHasKey('project.team', $portfolio);
        $this->assertArrayHasKey('project.team', $this->items($this->user('karimi@nioc.example'), 'en', 'PH-14'));
    }

    public function test_the_active_item_is_exact_not_a_prefix_match(): void
    {
        // /projects/PH-14/team starts with /projects/PH-14, so a prefix match
        // would light up Certificates while the user is on the team screen.
        $nav = app(Navigation::class)->build(
            $this->user('karimi@nioc.example'),
            'en',
            '/projects/PH-14/team',
            Project::where('code', 'PH-14')->first(),
        );

        $active = [];

        foreach ($nav as $section) {
            foreach ($section['items'] as $item) {
                if ($item['active']) {
                    $active[] = $item['key'];
                }
            }
        }

        $this->assertSame(['project.team'], $active);
    }

    public function test_the_sidebar_is_bilingual_and_flips_direction(): void
    {
        $karimi = $this->user('karimi@nioc.example');

        $this->actingAs($karimi)->get('/?lang=fa')
            ->assertSee('dir="rtl"', false)
            ->assertSee('سبد پروژه‌ها');

        $this->actingAs($karimi)->get('/?lang=en')
            ->assertSee('dir="ltr"', false)
            ->assertSee('Portfolio');
    }

    public function test_the_language_switch_carries_allowlisted_params_only(): void
    {
        $karimi = $this->user('karimi@nioc.example');

        // Allowlisted state survives the switch...
        $this->actingAs($karimi)->get('/projects/PH-14?lang=fa&page=2')
            ->assertSee('page=2', false);

        // ...but arbitrary input is NOT reflected back into an href, and the
        // dead `as=` tenant-switch parameter must never reappear: echoing it
        // makes a neutered control look alive and reads as a tenant leak.
        $html = (string) $this->actingAs($karimi)
            ->get('/projects/PH-14?lang=fa&as=EPC-SAHAND')
            ->getContent();

        $this->assertStringNotContainsString('EPC-SAHAND', $html);
    }

    public function test_every_shell_screen_can_collapse_the_sidebar(): void
    {
        // A sidebar with no way to close it is the complaint that prompted
        // this; a toggle on only some screens is the same bug, just rarer.
        $karimi = $this->user('karimi@nioc.example');

        foreach (['/', '/projects/new', '/projects/PH-14', '/projects/PH-14/team'] as $path) {
            $r = $this->actingAs($karimi)->get($path.'?lang=en');

            $r->assertSee('id="sb-burger"', false);
            // The control must point at the thing it controls, for a screen
            // reader to announce the relationship at all.
            $r->assertSee('aria-controls="sb"', false);
            $r->assertSee('aria-expanded', false);
            $r->assertSee('id="scrim"', false);
        }
    }

    public function test_the_toggle_is_outside_the_sidebar_it_toggles(): void
    {
        // If the button lived inside the drawer it would slide off-canvas with
        // it on mobile, and there would be no way to bring the sidebar back.
        $html = (string) $this->actingAs($this->user('karimi@nioc.example'))
            ->get('/?lang=en')->getContent();

        $aside = substr($html, (int) strpos($html, '<aside'), (int) strpos($html, '</aside>') - (int) strpos($html, '<aside'));

        $this->assertStringNotContainsString('id="sb-burger"', $aside);
        $this->assertStringContainsString('id="sb-burger"', $html);
    }

    public function test_the_collapsed_state_is_restored_before_first_paint(): void
    {
        // Server-rendered app: every click is a fresh document. Restoring the
        // preference only at the end of the body makes the sidebar flash open
        // on every navigation, which reads as a broken toggle.
        $html = (string) $this->actingAs($this->user('karimi@nioc.example'))
            ->get('/?lang=en')->getContent();

        $headEnd = (int) strpos($html, '</head>');
        $head = substr($html, 0, $headEnd);

        $this->assertStringContainsString('epcc.sb.rail', $head, 'state must be restored in <head>');
        $this->assertLessThan(
            (int) strpos($html, '<aside'),
            (int) strpos($html, 'epcc.sb.rail'),
            'the restore script must run before the sidebar markup',
        );
    }

    public function test_the_toggle_script_waits_for_the_button_to_exist(): void
    {
        // The sidebar renders BEFORE the content column (flex order), but the
        // burger lives in the content column - so the script is emitted before
        // the button it wires up. Without a readiness guard,
        // getElementById() returns null, the script bails, and the button
        // renders perfectly while doing absolutely nothing. That is precisely
        // the bug this guards.
        $html = (string) $this->actingAs($this->user('karimi@nioc.example'))
            ->get('/?lang=en')->getContent();

        $scriptPos = (int) strpos($html, "getElementById('sb-burger')");
        $buttonPos = (int) strpos($html, 'id="sb-burger"');

        $this->assertGreaterThan(0, $scriptPos);
        $this->assertGreaterThan(0, $buttonPos);

        // If the script really does precede the button, it MUST defer.
        if ($scriptPos < $buttonPos) {
            $this->assertStringContainsString(
                'DOMContentLoaded',
                $html,
                'the toggle script runs before its button exists and must wait for the DOM',
            );
        }
    }

    public function test_the_rail_collapse_uses_animatable_properties(): void
    {
        // The rail snapped open and shut because labels were hidden with
        // `display:none` - a DISCRETE property that cannot be interpolated, so
        // no amount of container animation helps. Guarding the mechanism, not
        // the look, because the look is invisible to PHPUnit.
        $html = (string) $this->actingAs($this->user('karimi@nioc.example'))
            ->get('/?lang=en')->getContent();

        $css = substr($html, (int) strpos($html, '<style'), (int) strpos($html, '</style>'));

        // The label-collapsing rule must not reach for display:none again.
        $this->assertMatchesRegularExpression(
            '/\.sb-i > span[^{]*\{[^}]*max-width:\s*0/s',
            $css,
            'labels must collapse via max-width so the transition can run',
        );

        // The panel itself must animate its width on DESKTOP - the original
        // bug was a transition that existed only inside the mobile media query.
        $desktop = substr($css, 0, (int) strpos($css, '@media (max-width:860px)'));

        $this->assertMatchesRegularExpression(
            '/\.sb\s*\{[^}]*transition:[^}]*(flex-basis|width)/s',
            $desktop,
            'the sidebar must transition its width outside the mobile query',
        );

        // Vestibular accessibility: animation must be defeatable by the OS.
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function test_the_sidebar_offers_a_way_out_on_every_screen(): void
    {
        // Sign-out lives in the sidebar now that the per-page topnav is gone.
        // A screen with no exit is the bug this whole change fixes.
        $karimi = $this->user('karimi@nioc.example');

        foreach (['/', '/projects/new', '/projects/PH-14', '/projects/PH-14/team'] as $path) {
            $this->actingAs($karimi)->get($path.'?lang=en')
                ->assertSee('action="/logout"', false);
        }
    }

    public function test_inspection_notification_menu_is_quality_entitled_without_document_read_or_issue_inheritance(): void
    {
        $items = $this->items($this->user('jafari@karan.example'), 'en', 'PH-14');
        $this->assertSame('available', $items['project.inspection-notifications']['state']);
        $this->assertStringContainsString('/inspection-notifications', $items['project.inspection-notifications']['url']);
        $starter = $this->items($this->user('hosseini@arvand.example'), 'en', 'KISH-1');
        $this->assertSame('locked', $starter['project.inspection-notifications']['state']);
        $this->assertNull($starter['project.inspection-notifications']['url']);
    }
}
