<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Project setup: who may create a project, which packs they may choose,
 * and who they may put on it.
 *
 * The interesting assertions here are the refusals. Creation working is
 * table stakes; creation being IMPOSSIBLE for a contractor, for an
 * unentitled pack, and past a plan quota is the product.
 */
final class ProjectSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    // ---- creation: the happy path ------------------------------------

    public function test_employer_can_open_the_create_form(): void
    {
        $r = $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/new?lang=en');

        $r->assertOk();
        $r->assertSee('Create a project');
        $r->assertSee('Standards framework');
    }

    public function test_employer_creates_a_project_with_partners_in_one_transaction(): void
    {
        $karimi = $this->userByEmail('karimi@nioc.example');
        $pars = DB::table('companies')->where('code', 'EPC-PARS')->value('id');
        $pmc = DB::table('companies')->where('code', 'PMC-TEHRAN')->value('id');

        $r = $this->actingAs($karimi)->post('/projects?lang=en', [
            'code' => 'SP-22',
            'name_en' => 'South Pars Phase 22 Utilities',
            'name_fa' => 'تأسیسات جانبی پارس جنوبی فاز ۲۲',
            'standards_pack' => 'ips-iran',
            'partners' => [$pars, $pmc],
            'partner_roles' => [$pars => 'CONTRACTOR', $pmc => 'CONSULTANT'],
        ]);

        $r->assertRedirect('/projects/SP-22?lang=en');

        $project = Project::where('code', 'SP-22')->firstOrFail();
        $this->assertSame('ips-iran', $project->standards_pack);
        $this->assertSame($karimi->company_id, $project->owner_company_id);

        // The owner MUST have a participation row, otherwise RLS hides the
        // project from the company that just created it.
        $rows = DB::table('project_company')->where('project_id', $project->id)
            ->pluck('role', 'company_id');
        $this->assertCount(3, $rows);
        $this->assertSame('EMPLOYER', $rows[$karimi->company_id]);
        $this->assertSame('CONTRACTOR', $rows[$pars]);
        $this->assertSame('CONSULTANT', $rows[$pmc]);

        // ...and the creator must be a member, or they cannot open it.
        // assertDatabaseHas issues its own unscoped query, which RLS now
        // (correctly) filters to nothing, so bind the tenant and count.
        $memberships = app(\App\Support\TenantContext::class)->runAs(
            $karimi->company_id,
            fn () => ProjectMembership::where('user_id', $karimi->id)
                ->where('project_id', $project->id)->count(),
        );
        $this->assertSame(1, $memberships, 'The project creator was not made a member.');
    }

    public function test_a_created_project_is_immediately_openable_by_its_creator(): void
    {
        $karimi = $this->userByEmail('karimi@nioc.example');

        $this->actingAs($karimi)->post('/projects?lang=en', [
            'code' => 'SP-23',
            'name_en' => 'South Pars 23',
            'name_fa' => 'پارس جنوبی ۲۳',
            'standards_pack' => 'iso',
        ]);

        $this->actingAs($karimi)->get('/projects/SP-23?lang=en')->assertOk();
    }

    // ---- creation: the refusals --------------------------------------

    public function test_contractor_cannot_reach_the_create_form(): void
    {
        // rezaei is a project manager at EPC-PARS on the `full` plan: he has
        // budget, modules and capabilities. He is still not an employer.
        $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/projects/new?lang=en')
            ->assertForbidden();
    }

    public function test_contractor_cannot_post_a_project_either(): void
    {
        // The form being hidden is not a control. The POST must refuse too.
        $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->post('/projects?lang=en', [
                'code' => 'ROGUE-1',
                'name_en' => 'Rogue',
                'name_fa' => 'ناسازگار',
                'standards_pack' => 'iso',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', ['code' => 'ROGUE-1']);
    }

    public function test_an_employer_user_without_the_capability_is_still_refused(): void
    {
        // REGRESSION. The tenant_type check in the controller would refuse a
        // contractor anyway, so it can hide a broken RBAC gate. This user is
        // at an OWN company on the `special` plan (every module, every pack)
        // and holds NO role granting project.create. Only gate 4 can stop
        // him. If AuthorizationPipeline ever short-circuits tenant-level
        // checks after ENTITLEMENT again, this test goes red.
        $nioc = DB::table('companies')->where('code', 'NIOC-AFF')->value('id');

        $clerk = $this->provisionIdentity(fn () => User::create([
            'company_id' => $nioc,
            'name' => 'کارشناس دفتری',
            'email' => 'clerk@nioc.example',
            'password' => bcrypt('password'),
            'is_active' => true,
            'locale' => 'fa',
        ]));

        $this->actingAs($clerk)->get('/projects/new?lang=en')->assertForbidden();

        $this->actingAs($clerk)
            ->post('/projects?lang=en', [
                'code' => 'NOCAP-1',
                'name_en' => 'No capability',
                'name_fa' => 'بدون اختیار',
                'standards_pack' => 'iso',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', ['code' => 'NOCAP-1']);
    }

    public function test_unauthenticated_visitor_is_sent_to_login(): void
    {
        $this->get('/projects/new')->assertRedirect('/login');
        $this->post('/projects', [])->assertRedirect('/login');
    }

    public function test_pack_choice_is_limited_to_what_the_plan_entitles(): void
    {
        // NIOC is on `special` (all packs) so it sees all three...
        $r = $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/new?lang=en');
        $r->assertSee('value="awp"', false);
        $r->assertSee('value="iso"', false);
        $r->assertSee('value="ips-iran"', false);
    }

    public function test_an_unentitled_pack_is_refused_even_when_posted_directly(): void
    {
        // Give the employer a starter plan (iso only), then try to post awp.
        $karimi = $this->userByEmail('karimi@nioc.example');
        $starter = DB::table('plans')->where('code', 'starter')->value('id');
        DB::table('companies')->where('id', $karimi->company_id)->update(['plan_id' => $starter]);

        $this->actingAs($karimi)
            ->post('/projects?lang=en', [
                'code' => 'AWP-1',
                'name_en' => 'Sneaky AWP',
                'name_fa' => 'ای‌دبلیوپی',
                'standards_pack' => 'awp',
            ])
            ->assertSessionHasErrors('standards_pack');

        $this->assertDatabaseMissing('projects', ['code' => 'AWP-1']);
    }

    public function test_plan_project_quota_is_enforced(): void
    {
        // starter allows max_projects = 1. NIGC owns BB-2 already.
        $ahmadi = $this->userByEmail('ahmadi@nigc.example');
        $starter = DB::table('plans')->where('code', 'starter')->value('id');
        DB::table('companies')->where('id', $ahmadi->company_id)->update(['plan_id' => $starter]);

        $this->actingAs($ahmadi)
            ->post('/projects?lang=en', [
                'code' => 'BB-3',
                'name_en' => 'Bid Boland 3',
                'name_fa' => 'بیدبلند ۳',
                'standards_pack' => 'iso',
            ])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('projects', ['code' => 'BB-3']);
    }

    public function test_duplicate_project_code_is_refused(): void
    {
        $this->actingAs($this->userByEmail('karimi@nioc.example'))
            ->post('/projects?lang=en', [
                'code' => 'PH-14',
                'name_en' => 'Clash',
                'name_fa' => 'تداخل',
                'standards_pack' => 'iso',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_lowercase_project_code_is_refused(): void
    {
        $this->actingAs($this->userByEmail('karimi@nioc.example'))
            ->post('/projects?lang=en', [
                'code' => 'sp-24',
                'name_en' => 'Lowercase',
                'name_fa' => 'کوچک',
                'standards_pack' => 'iso',
            ])
            ->assertSessionHasErrors('code');
    }

    // ---- team screen --------------------------------------------------

    public function test_member_sees_the_team_screen_for_their_project(): void
    {
        $r = $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/PH-14/team?lang=en');

        $r->assertOk();
        $r->assertSee('Project team');
        $r->assertSee('EPC-PARS');
        $r->assertSee('NIOC-AFF'); // participants are visible by name...
    }

    public function test_team_screen_of_a_foreign_project_is_404(): void
    {
        // arvand is only on KISH-1. PH-14 must not even acknowledge itself.
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))
            ->get('/projects/PH-14/team?lang=en')
            ->assertNotFound();
    }

    public function test_employer_can_add_a_colleague_from_their_own_company(): void
    {
        $karimi = $this->userByEmail('karimi@nioc.example');
        $project = Project::where('code', 'PH-14')->firstOrFail();

        // Give NIOC a second person to add.
        $colleague = $this->provisionIdentity(fn () => User::create([
            'company_id' => $karimi->company_id,
            'name' => 'مهندس نوروزی',
            'email' => 'norouzi@nioc.example',
            'password' => bcrypt('password'),
            'is_active' => true,
            'locale' => 'fa',
        ]));

        $this->actingAs($karimi)
            ->post('/projects/PH-14/team?lang=en', [
                'user_id' => $colleague->id,
                'role_codes' => ['employer_rep'],
                'authority_limit' => 25_000_000_000,
            ])
            ->assertRedirect('/projects/PH-14/team?lang=en');

        // Verifying the write needs a bound tenant now that
        // project_memberships carries RLS: the POST above succeeded inside
        // BindTenant, but this assertion runs outside any middleware.
        $m = app(\App\Support\TenantContext::class)->runAs(
            $karimi->company_id,
            fn () => ProjectMembership::where('user_id', $colleague->id)
                ->where('project_id', $project->id)->firstOrFail(),
        );

        $this->assertSame(['employer_rep'], $m->role_codes);
        $this->assertSame('25000000000.00', $m->authority_limit);
    }

    public function test_you_cannot_add_another_companys_user_to_a_project(): void
    {
        // karimi (employer) tries to plant a user inside the contractor's
        // tenant. Refused: you may only add your own staff.
        $karimi = $this->userByEmail('karimi@nioc.example');
        $foreign = $this->userByEmail('sadeghi@pars.example');

        DB::table('project_memberships')
            ->where('user_id', $foreign->id)->delete();

        $this->actingAs($karimi)
            ->post('/projects/PH-14/team?lang=en', [
                'user_id' => $foreign->id,
                'role_codes' => ['employer_rep'],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('project_memberships', [
            'user_id' => $foreign->id,
            'project_id' => Project::where('code', 'PH-14')->value('id'),
        ]);
    }

    public function test_a_user_without_project_manage_gets_a_read_only_team_screen(): void
    {
        // sadeghi is a QC inspector: cert.* and punch.*, no project.*
        $r = $this->actingAs($this->userByEmail('sadeghi@pars.example'))->get('/projects/PH-14/team?lang=en');

        $r->assertOk();
        $r->assertSee('read-only');
        $r->assertDontSee('Add a colleague from your company');
    }

    public function test_add_member_is_refused_for_a_user_without_project_manage(): void
    {
        $sadeghi = $this->userByEmail('sadeghi@pars.example');

        $target = $this->provisionIdentity(fn () => User::create([
            'company_id' => $sadeghi->company_id,
            'name' => 'کارشناس جدید',
            'email' => 'new@pars.example',
            'password' => bcrypt('password'),
            'is_active' => true,
            'locale' => 'fa',
        ]));

        $this->actingAs($sadeghi)
            ->post('/projects/PH-14/team?lang=en', [
                'user_id' => $target->id,
                'role_codes' => ['qc_inspector'],
            ])
            ->assertForbidden();
    }

    // ---- gate 3: membership, not just company participation -------------

    /**
     * REGRESSION (found live, 2026-09-09).
     *
     * A brand-new employee at a participating contractor, with no project
     * membership at all, could read the whole portfolio and every payment
     * certificate on it - including the 43.2B PH-14 figure - because their
     * COMPANY was on the project. The demo fixture hid this: every seeded
     * user's memberships happened to mirror their company's participation.
     */
    private function newbieAtPars(): User
    {
        return $this->provisionIdentity(fn () => User::create([
            'company_id' => DB::table('companies')->where('code', 'EPC-PARS')->value('id'),
            'name' => 'تازه‌وارد',
            'email' => 'newbie@pars.example',
            'password' => bcrypt('password'),
            'is_active' => true,
            'locale' => 'fa',
        ]));
    }

    public function test_company_participation_alone_does_not_reveal_the_portfolio(): void
    {
        $newbie = $this->newbieAtPars();
        $this->assertSame(0, ProjectMembership::where('user_id', $newbie->id)->count());

        $r = $this->actingAs($newbie)->get('/?lang=en');

        $r->assertOk();
        // EPC-PARS participates in both. This person is staffed on neither.
        $r->assertDontSee('PH-14');
        $r->assertDontSee('BB-2');
    }

    public function test_company_participation_alone_does_not_reveal_a_certificate(): void
    {
        $newbie = $this->newbieAtPars();

        $this->actingAs($newbie)->get('/projects/PH-14?lang=en')->assertNotFound();
        $this->actingAs($newbie)->get('/projects/PH-14/team?lang=en')->assertNotFound();
    }

    public function test_granting_a_membership_is_what_opens_the_project(): void
    {
        // The other half of the proof: the refusal above must be caused by
        // the missing membership and nothing else.
        $newbie = $this->newbieAtPars();

        $this->provisionIdentity(fn () => ProjectMembership::create([
            'user_id' => $newbie->id,
            'project_id' => Project::where('code', 'PH-14')->value('id'),
            'role_codes' => ['site_engineer'],
        ]));

        $this->actingAs($newbie)->get('/projects/PH-14?lang=en')->assertOk();
        $this->actingAs($newbie)->get('/?lang=en')->assertSee('PH-14');
    }

    // ---- entry points ---------------------------------------------------

    public function test_the_new_project_button_appears_only_for_employers(): void
    {
        $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/?lang=en')
            ->assertSee('/projects/new?lang=en', false);

        // A contractor must not be shown a door they cannot open.
        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/?lang=en')
            ->assertDontSee('/projects/new', false);
    }

    public function test_the_project_screen_links_to_its_team(): void
    {
        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/PH-14?lang=en')
            ->assertSee('/projects/PH-14/team?lang=en', false);
    }

    // ---- bilingual ----------------------------------------------------

    public function test_the_create_form_is_rtl_persian_by_default(): void
    {
        $r = $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/new');

        $r->assertOk();
        $r->assertSee('dir="rtl"', false);
        $r->assertSee('ایجاد پروژهٔ جدید');
    }

    public function test_the_team_screen_is_bilingual(): void
    {
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->actingAs($rezaei)->get('/projects/PH-14/team')
            ->assertSee('تیم پروژه')->assertSee('dir="rtl"', false);

        $this->actingAs($rezaei)->get('/projects/PH-14/team?lang=en')
            ->assertSee('Project team')->assertSee('dir="ltr"', false);
    }
}
