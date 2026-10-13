<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\McCertificate;
use App\Models\Project;
use App\Models\PunchItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\McCertificateService;
use App\Services\PdfRenderer;
use App\Services\PunchListService;
use App\Support\AuthorizationPipeline;
use App\Support\PunchCategory;
use App\Support\PunchState;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * Punch list and Mechanical Completion (SRS FR-COM-04/05, journey 5).
 */
final class PunchController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly PunchListService $punch,
        private readonly McCertificateService $mcc,
        private readonly PdfRenderer $pdf,
    ) {
    }

    /** The punch register for a project, grouped by subsystem. */
    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'punch.view', $project, $lang);

        [$subsystems, $items, $companies, $blocking] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project): array {
                $subsystems = Subsystem::with(['punchItems', 'certificate'])
                    ->orderBy('code')
                    ->get();

                $items = PunchItem::with(['subsystem', 'company', 'responsibleCompany'])
                    ->orderByDesc('sequence')
                    ->get();

                $participantIds = DB::table('project_company')
                    ->where('project_id', $project->id)
                    ->pluck('company_id')
                    ->push($project->owner_company_id)
                    ->unique();

                $companies = Company::whereIn('id', $participantIds)->orderBy('code')->get();

                // Which A-items stand in the way, per subsystem. Derived here
                // so the view never has to know the rule.
                $blocking = $subsystems->mapWithKeys(fn (Subsystem $s) => [
                    $s->id => $this->mcc->blockingItems($s),
                ]);

                return [$subsystems, $items, $companies, $blocking];
            },
            $project->id,
        );

        return view('punch.index', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'subsystems' => $subsystems,
            'items' => $items,
            'companies' => $companies,
            'blocking' => $blocking,
            'categories' => PunchCategory::ALL,
            'isEmployer' => $project->owner_company_id === $user->company_id,
            'canRaise' => $this->authz->allows($user, 'punch.raise', $project),
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Raise a new punch item. */
    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'punch.raise', $project, $lang);

        $data = $request->validate([
            'subsystem_id' => ['required', 'uuid'],
            'category' => ['required', 'string', 'in:'.implode(',', PunchCategory::ALL)],
            'description_en' => ['required', 'string', 'max:2000'],
            'description_fa' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'responsible_company_id' => ['nullable', 'uuid'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($data, $user, $lang): void {
                $subsystem = Subsystem::findOrFail($data['subsystem_id']);

                $item = $this->punch->raise(
                    $subsystem,
                    $user,
                    $data['category'],
                    $data['description_en'],
                    $data['description_fa'],
                    $data['location'] ?? null,
                    $lang,
                );

                // Assigning at the same time is the normal case on a walkdown.
                if (! empty($data['responsible_company_id'])) {
                    $this->punch->assign($item, Company::findOrFail($data['responsible_company_id']), $lang);
                }
            },
            $project->id,
        );

        return redirect()->to($this->backTo($request, $projectCode));
    }

    /** Move one item along its lifecycle. */
    public function update(Request $request, string $projectCode, string $itemId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'punch.view', $project, $lang);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:assign,fix,verify,reject,close'],
            'note' => ['nullable', 'string', 'max:2000'],
            'responsible_company_id' => ['nullable', 'uuid'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($data, $itemId, $user, $lang): void {
                $item = PunchItem::findOrFail($itemId);

                match ($data['action']) {
                    'assign' => $this->punch->assign(
                        $item, Company::findOrFail($data['responsible_company_id']), $lang,
                    ),
                    'fix' => $this->punch->markFixed($item, $user, (string) ($data['note'] ?? ''), $lang),
                    'verify' => $this->punch->verify($item, $user, true, (string) ($data['note'] ?? ''), $lang),
                    'reject' => $this->punch->verify($item, $user, false, (string) ($data['note'] ?? ''), $lang),
                    'close' => $this->punch->close($item, $user, $lang),
                };
            },
            $project->id,
        );

        return redirect()->to($this->backTo($request, $projectCode));
    }

    /** Draft, except, or issue a Mechanical Completion certificate. */
    public function certificate(Request $request, string $projectCode, string $subsystemId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'punch.view', $project, $lang);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:draft,except,issue'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($data, $subsystemId, $user, $lang): void {
                $subsystem = Subsystem::findOrFail($subsystemId);

                if ($data['action'] === 'draft') {
                    $this->mcc->draft($subsystem, $user, $lang);

                    return;
                }

                $certificate = McCertificate::where('subsystem_id', $subsystem->id)->firstOrFail();

                match ($data['action']) {
                    'except' => $this->mcc->approveException(
                        $certificate, $user, (string) ($data['reason'] ?? ''), $lang,
                    ),
                    'issue' => $this->mcc->issue($certificate, $user, $lang),
                };
            },
            $project->id,
        );

        return redirect()->to($this->backTo($request, $projectCode));
    }

    /** Export a Mechanical Completion certificate as a PDF. */
    public function certificatePdf(Request $request, string $projectCode, string $subsystemId): Response
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'punch.view', $project, $lang);

        [$certificate, $subsystem, $items, $openA, $issuer, $issuerCompany, $approver] = $this->tenant->runAs(
            $user->company_id,
            function () use ($subsystemId): array {
                $subsystem = Subsystem::findOrFail($subsystemId);
                $certificate = McCertificate::where('subsystem_id', $subsystem->id)->firstOrFail();

                $items = PunchItem::where('subsystem_id', $subsystem->id)->orderBy('sequence')->get();
                $openA = $items->filter(fn (PunchItem $i) => $i->blocksMc())->values();

                /*
                 * Plain User::find() on an RLS-protected table, deliberately.
                 *
                 * The issuer is usually from a different company than whoever
                 * is reading the certificate - the owner issues, the
                 * contractor downloads - so this looked like it needed the
                 * identity hatch. It does not: the `users` counterparty policy
                 * already exposes staff of companies that share a project, and
                 * the whole call runs inside runAs() on this project. Verified
                 * from all four participant sides.
                 *
                 * It matters because the blade guards the signatory line with
                 * @if($issuer): a null issuer does not raise, it silently
                 * drops the "issued by" row from a document whose purpose is
                 * to record who signed. Pinned by PdfExportTest (M105).
                 */
                $issuer = $certificate->issued_by ? User::find($certificate->issued_by) : null;
                $issuerCompany = $issuer ? Company::find($issuer->company_id) : null;
                $approver = $certificate->exception_approved_by
                    ? User::find($certificate->exception_approved_by)
                    : null;

                return [$certificate, $subsystem, $items, $openA, $issuer, $issuerCompany, $approver];
            },
            $project->id,
        );

        $html = view('print.mc-certificate', [
            'lang' => $lang,
            'title' => $certificate->number,
            'project' => $project,
            'certificate' => $certificate,
            'subsystem' => $subsystem,
            'items' => $items,
            'openA' => $openA,
            'issuer' => $issuer,
            'issuerCompany' => $issuerCompany,
            'exceptionApprover' => $approver,
            'generatedAt' => $this->today($lang),
        ])->render();

        try {
            $bytes = $this->pdf->render($html);
        } catch (\RuntimeException $e) {
            // 503, not 500: the document is fine, the host is missing a tool.
            abort(503, $e->getMessage());
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$certificate->number.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function backTo(Request $request, string $projectCode): string
    {
        $query = ['lang' => $this->lang($request)];

        // Carry the preview session forward. Never reflect the raw query
        // string - only this allowlisted pair.
        if ($request->has('sid')) {
            $query['sid'] = $request->query('sid');
        }

        return "/projects/{$projectCode}/punch?".http_build_query($query);
    }

    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function authorize(Request $request, $user, string $capability, Project $project, string $lang): void
    {
        $denial = $this->authz->check($user, $capability, $project);

        if ($denial !== null) {
            abort(403, $denial->message($lang));
        }
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
}
