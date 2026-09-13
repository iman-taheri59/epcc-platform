<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PaymentCertificate;
use App\Models\Project;
use App\Models\User;
use App\Services\GateService;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * Multi-project vertical slice.
 *
 * Two views over the same data:
 *   - portfolio: every project the acting company can see, with totals
 *   - project:   one project, scoped by RLS via epcc.project_id
 *
 * Which projects and rows appear is decided by PostgreSQL policies, not by
 * where clauses here. The controller only chooses the scope.
 */
final class CertificateController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly GateService $gates,
        private readonly AuthorizationPipeline $authz,
    ) {
    }

    /** Portfolio: all projects visible to the acting company. */
    public function index(Request $request): View
    {
        $lang = $this->lang($request);
        $acting = $this->resolveActing($request);

        $projects = $this->visibleProjects($acting, $request->user());

        $cards = $this->tenant->runAs($acting->id, function () use ($projects, $acting): array {
            $limit = $this->authorityLimit($acting);
            $out = [];

            foreach ($projects as $project) {
                // Scope RLS to this project, then let the database decide.
                $this->tenant->scopeToProject($project->id);

                $certs = PaymentCertificate::orderBy('serial')->get();
                $blocked = 0;
                $ungated = 0;
                $net = 0.0;

                foreach ($certs as $c) {
                    $c->setRelation('project', $project);
                    $v = $this->gates->evaluateCertificate($c, $limit);
                    $blocked += $v->isBlocked() ? 1 : 0;
                    // Counted separately: an ungated certificate is not
                    // "healthy", it is simply unexamined by this pack.
                    $ungated += $v->isUngated() ? 1 : 0;
                    $net += (float) $c->net_amount;
                }

                $out[] = [
                    'project' => $project,
                    'count'   => $certs->count(),
                    'blocked' => $blocked,
                    'ungated' => $ungated,
                    'net'     => $net,
                ];
            }

            // Always leave the scope cleared for anything later in the request.
            $this->tenant->scopeToProject(null);

            return $out;
        });

        return view('portfolio', [
            'lang'       => $lang,
            // Drives the "new project" button. The button is a convenience,
            // not a control: ProjectSetupController re-checks on both verbs.
            'canCreate'  => $request->user()->company->tenant_type === 'OWN'
                && $this->authz->allows($request->user(), 'project.create'),
            'dir'        => $lang === 'fa' ? 'rtl' : 'ltr',
            'user'       => $request->user(),
            'acting'     => $acting,
            'cards'      => $cards,
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** One project. */
    public function show(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $acting = $this->resolveActing($request);

        $projects = $this->visibleProjects($acting, $request->user());
        $project = $projects->firstWhere('code', $projectCode);

        abort_if($project === null, 404);

        $user = $request->user();

        [$rows, $limit] = $this->tenant->runAs(
            $acting->id,
            function () use ($acting, $project, $user): array {
                $limit = $this->authorityLimit($acting, $project, $user);

                $rows = PaymentCertificate::with('company')
                    ->orderBy('serial')
                    ->get()
                    ->map(function (PaymentCertificate $c) use ($project, $limit): array {
                        $c->setRelation('project', $project);

                        return ['cert' => $c, 'verdict' => $this->gates->evaluateCertificate($c, $limit)];
                    })
                    ->all();

                return [$rows, $limit];
            },
            $project->id, // <- RLS project scope
        );

        return view('certificates', [
            'lang'       => $lang,
            'dir'        => $lang === 'fa' ? 'rtl' : 'ltr',
            'user'       => $user,
            'acting'     => $acting,
            'project'    => $project,
            'projects'   => $projects,
            'rows'       => $rows,
            'limit'      => $limit,
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /**
     * Projects this USER may see.
     *
     * Two conditions, both required (ARC gate 3, MEMBERSHIP):
     *
     *   1. the acting company participates in the project (or owns it), and
     *   2. the user personally holds a membership on it.
     *
     * Condition 2 is not redundant. Company participation is a commercial
     * fact - "EPC-PARS is on Phase 14" - and it is what the RLS predicate
     * keys on. It is NOT an individual grant. Without condition 2 every new
     * hire at a participating contractor could read every certificate on
     * every project that contractor has ever touched, from their first
     * login, because their company is on it. Staffing a person onto a
     * project is a deliberate act; this is where that act is enforced.
     *
     * `projects` is not RLS-protected (it is reference data shared across
     * participants), so both conditions are filtered explicitly here.
     *
     * @return \Illuminate\Support\Collection<int,Project>
     */
    private function visibleProjects(Company $acting, ?User $user = null)
    {
        return Project::query()
            ->where(function ($q) use ($acting): void {
                $q->where('owner_company_id', $acting->id)
                    ->orWhereExists(function ($sub) use ($acting): void {
                        $sub->selectRaw('1')
                            ->from('project_company')
                            ->whereColumn('project_company.project_id', 'projects.id')
                            ->where('project_company.company_id', $acting->id);
                    });
            })
            ->when($user !== null, function ($q) use ($user): void {
                $q->whereExists(function ($sub) use ($user): void {
                    $sub->selectRaw('1')
                        ->from('project_memberships')
                        ->whereColumn('project_memberships.project_id', 'projects.id')
                        ->where('project_memberships.user_id', $user->id);
                });
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * Delegation of authority.
     *
     * Prefer the per-user, per-project limit set on the membership; fall back
     * to a tenant-type default. Authority is a property of the PERSON, not of
     * the company - two people at the same contractor routinely hold very
     * different limits.
     */
    private function authorityLimit(Company $acting, ?Project $project = null, $user = null): float
    {
        if ($user !== null && $project !== null) {
            $membership = $user->membershipFor($project->id);

            if ($membership?->authority_limit !== null) {
                return (float) $membership->authority_limit;
            }
        }

        return $acting->tenant_type === 'OWN' ? 100_000_000_000 : 50_000_000_000;
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'fa';
    }

    private function today(string $lang): string
    {
        $d = Jalalian::now()->format('Y/m/d');

        return $lang === 'fa' ? CalendarUtils::convertNumbers($d) : $d;
    }

    /**
     * The acting company is the authenticated user's tenant.
     *
     * No `?as=` override: identity comes from the session, and the same value
     * is pushed into `epcc.company_id` by BindTenant, so what the screen shows
     * and what PostgreSQL permits cannot diverge.
     */
    private function resolveActing(Request $request): Company
    {
        return $request->user()->company;
    }
}
