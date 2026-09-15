<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectArchiveService;
use App\Services\StandardsPackRepository;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use App\Support\TenantType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Project setup: create a project, choose its standards pack, invite the
 * participating companies, and put people on it.
 *
 * This is the screen that makes everything else operable rather than seeded.
 * Three rules shape it:
 *
 *   1. Only an OWNER company may create a project. The employer owns the
 *      project; contractors are invited to it. Enforced by capability
 *      (`project.create`) AND by tenant type, because a plan misconfiguration
 *      should not be able to turn a contractor into an employer.
 *
 *   2. The standards pack is chosen ONCE at creation and is entitlement-
 *      checked. A company whose plan covers ISO only cannot start an AWP
 *      project. Changing the pack later is deliberately not offered: live
 *      documents are numbered and gated against the pack that created them.
 *
 *   3. Everything happens in ONE transaction. A project that exists with no
 *      owner participation row is invisible even to the company that just
 *      created it, and a half-built project is worse than no project.
 */
final class ProjectSetupController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly StandardsPackRepository $packs,
    ) {
    }

    /** Step 1: the create form. */
    public function create(Request $request): View
    {
        $lang = $this->lang($request);
        $user = $request->user();

        $this->authorizeCreate($user, $lang);

        return view('projects.create', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'packs' => $this->entitledPacks($user->company),
            'partners' => $this->invitableCompanies($user->company),
            'roles' => Role::orderBy('code')->get(),
        ]);
    }

    /** Step 2: persist it. */
    public function store(Request $request): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();

        $this->authorizeCreate($user, $lang);

        $entitled = $this->entitledPacks($user->company);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/', Rule::unique('projects', 'code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_fa' => ['required', 'string', 'max:255'],
            'standards_pack' => ['required', 'string', Rule::in($entitled)],
            'partners' => ['array'],
            'partners.*' => ['uuid', Rule::exists('companies', 'id')],
            'partner_roles' => ['array'],
        ], [
            'code.regex' => $lang === 'fa'
                ? 'کد پروژه فقط می‌تواند شامل حروف بزرگ لاتین، عدد و خط تیره باشد.'
                : 'Project code may contain only capital letters, digits and hyphens.',
            'standards_pack.in' => $lang === 'fa'
                ? 'بستهٔ استاندارد انتخاب‌شده در طرح اشتراک شما نیست.'
                : 'That standards pack is not included in your plan.',
        ]);

        // Plan quota (SRS BR-02). Checked here rather than in the pipeline
        // because it is a count, not a capability.
        $plan = $user->company->plan;
        if ($plan?->max_projects !== null) {
            // ACTIVE projects only. An archived project is finished work kept
            // for the record, and charging a licence slot for it would push
            // customers to delete the evidence of a completed job in order to
            // start the next one. Archiving is the alternative to deletion, so
            // it has to be free of the quota or it is not an alternative.
            $owned = Project::where('owner_company_id', $user->company_id)->active()->count();

            if ($owned >= $plan->max_projects) {
                $archived = Project::where('owner_company_id', $user->company_id)->archived()->count();
                $hint = $archived > 0
                    ? ($lang === 'fa'
                        ? " ({$archived} پروژهٔ بایگانی‌شده شمارش نمی‌شود.)"
                        : " ({$archived} archived project(s) do not count.)")
                    : '';

                throw ValidationException::withMessages([
                    'code' => ($lang === 'fa'
                        ? "طرح «{$plan->name_fa}» حداکثر {$plan->max_projects} پروژهٔ فعال را مجاز می‌داند. یک پروژه را بایگانی کنید یا طرح را ارتقا دهید."
                        : "The {$plan->name_en} plan allows at most {$plan->max_projects} active project(s). Archive one, or upgrade to add more.").$hint,
                ]);
            }
        }

        $partnerRoles = $request->input('partner_roles', []);

        $project = DB::transaction(function () use ($data, $user, $partnerRoles): Project {
            $project = Project::create([
                'code' => $data['code'],
                'name_en' => $data['name_en'],
                'name_fa' => $data['name_fa'],
                'standards_pack' => $data['standards_pack'],
                'owner_company_id' => $user->company_id,
            ]);

            // The owner participates in its own project. Without this row the
            // RLS predicate (which consults project_company) would hide the
            // project from the company that just created it.
            DB::table('project_company')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'role' => 'EMPLOYER',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($data['partners'] ?? [] as $companyId) {
                if ($companyId === $user->company_id) {
                    continue; // already added as EMPLOYER
                }

                $company = Company::find($companyId);

                DB::table('project_company')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $companyId,
                    'role' => $this->contractRoleFor($company, $partnerRoles[$companyId] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Put the creator on the project so they can actually open it.
            // The DB trigger requires the company participation row to exist
            // first - which it does, above, in this same transaction.
            ProjectMembership::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'role_codes' => ['employer_rep'],
            ]);

            return $project;
        });

        return redirect('/projects/'.$project->code.'?lang='.$lang);
    }

    /** Team screen for an existing project. */
    public function team(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();

        $project = $this->visibleProject($user, $projectCode);

        $participants = DB::table('project_company')
            ->join('companies', 'companies.id', '=', 'project_company.company_id')
            ->where('project_company.project_id', $project->id)
            ->orderBy('companies.code')
            ->get(['companies.id', 'companies.code', 'companies.name_en', 'companies.name_fa', 'project_company.role']);

        // Members are listed per company so the commercial boundary stays
        // visible: you can see WHO is on the project, which is not the same
        // as seeing their commercial data.
        $members = ProjectMembership::with(['user.company'])
            ->where('project_id', $project->id)
            ->get()
            ->sortBy(fn ($m) => $m->user->company->code.$m->user->name)
            ->values();

        return view('projects.team', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'participants' => $participants,
            'members' => $members,
            'roles' => Role::orderBy('code')->get(),
            'candidates' => User::with('company')
                ->where('company_id', $user->company_id)
                ->where('is_active', true)
                ->whereNotIn('id', ProjectMembership::where('project_id', $project->id)->pluck('user_id'))
                ->orderBy('name')
                ->get(),
            'canManage' => $this->authz->allows($user, 'project.manage', $project),
        ]);
    }

    /** Add one of your own people to a project. */
    public function addMember(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->visibleProject($user, $projectCode);

        $denial = $this->authz->check($user, 'project.manage', $project);

        if ($denial !== null) {
            abort(403, $denial->message($lang));
        }

        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role_codes' => ['required', 'array', 'min:1'],
            'role_codes.*' => ['string', Rule::exists('roles', 'code')],
            'authority_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        // You may only add people from YOUR OWN company. Inviting another
        // company's staff would let an employer plant a user inside a
        // contractor's tenant.
        $candidate = User::where('id', $data['user_id'])
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        ProjectMembership::create([
            'user_id' => $candidate->id,
            'project_id' => $project->id,
            'role_codes' => $data['role_codes'],
            'authority_limit' => $data['authority_limit'] ?? null,
        ]);

        return redirect('/projects/'.$project->code.'/team?lang='.$lang);
    }

    // ---------------------------------------------------------------

    private function authorizeCreate(User $user, string $lang): void
    {
        $denial = $this->authz->check($user, 'project.create');

        if ($denial !== null) {
            abort(403, $denial->message($lang));
        }

        // Belt and braces: only an employer/owner tenant creates projects.
        if ($user->company->tenant_type !== 'OWN') {
            abort(403, $lang === 'fa'
                ? 'تنها شرکت کارفرما می‌تواند پروژه ایجاد کند.'
                : 'Only an employer company may create a project.');
        }
    }

    /** Packs on disk, filtered by what the company's plan entitles. */
    private function entitledPacks(Company $company): array
    {
        $plan = $company->plan;

        return array_values(array_filter(
            $this->packs->available(),
            fn (string $p): bool => $plan !== null && $plan->allowsPack($p),
        ));
    }

    private function invitableCompanies(Company $owner)
    {
        return Company::where('id', '!=', $owner->id)
            ->orderBy('code')
            ->get();
    }

    private function contractRoleFor(?Company $company, ?string $requested): string
    {
        // Contract ROLES on a project, which are not the same vocabulary as
        // company TYPES: a PMC-type company holds the CONSULTANT role, an
        // MC-type company (مدیریت پیمان) holds the MC role. Kept as literals
        // because they name the role, not the tenant type.
        $allowed = ['CONTRACTOR', 'CONSULTANT', 'MC', 'TPI', 'VENDOR', 'SUB'];

        if ($requested !== null && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return match ($company?->tenant_type) {
            TenantType::PMC => 'CONSULTANT',
            TenantType::MC => 'MC',
            TenantType::TPI => 'TPI',
            TenantType::VEN => 'VENDOR',
            default => 'CONTRACTOR',
        };
    }

    /**
     * A project this user may open: their company must participate AND they
     * must personally be a member (ARC gate 3). Company participation alone
     * is a commercial fact, not an individual grant - see the longer note on
     * CertificateController::visibleProjects().
     */
    private function visibleProject(User $user, string $code): Project
    {
        $project = Project::query()
            ->where('code', $code)
            ->where(function ($q) use ($user): void {
                $q->where('owner_company_id', $user->company_id)
                    ->orWhereExists(function ($sub) use ($user): void {
                        $sub->selectRaw('1')
                            ->from('project_company')
                            ->whereColumn('project_company.project_id', 'projects.id')
                            ->where('project_company.company_id', $user->company_id);
                    });
            })
            ->whereExists(function ($sub) use ($user): void {
                $sub->selectRaw('1')
                    ->from('project_memberships')
                    ->whereColumn('project_memberships.project_id', 'projects.id')
                    ->where('project_memberships.user_id', $user->id);
            })
            ->first();

        abort_if($project === null, 404);

        return $project;
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'fa';
    }

    /**
     * Archive a project (ADR-013 §7.2).
     *
     * Deliberately a POST with a confirmation in the UI rather than a toggle:
     * the effect is that every other company on the job loses the ability to
     * write, which they find out by being refused. Reversible, but not
     * something to discover by mis-clicking.
     */
    public function archive(Request $request, string $projectCode, ProjectArchiveService $archives): RedirectResponse
    {
        $user = $request->user();
        $project = $this->visibleProject($user, $projectCode);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $archives->archive($project, $user, $data['reason'] ?? null);

        return redirect()
            ->route('project', ['projectCode' => $project->code, 'lang' => $this->lang($request)])
            ->with('status', __('epcc.project.archived_badge'));
    }

    /** Reopen an archived project. Re-checks the plan quota. */
    public function reopen(Request $request, string $projectCode, ProjectArchiveService $archives): RedirectResponse
    {
        $user = $request->user();
        $project = $this->visibleProject($user, $projectCode);

        $archives->reopen($project, $user);

        return redirect()
            ->route('project', ['projectCode' => $project->code, 'lang' => $this->lang($request)])
            ->with('status', __('epcc.project.state.active'));
    }
}
