<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\PmsRevisionDraftService;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/** Edit only PAD material leaves in the current nominated PMS WIP copy. */
final class PmsRevisionDraftController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly PmsRevisionDraftService $drafts,
    ) {}

    /** Show the current revision's PAD-material working copies. */
    public function editPads(Request $request, string $projectCode, string $documentId): View
    {
        $lang = $this->lang($request);
        $user = $this->currentActor($request);
        $project = $this->resolveProject($user, $projectCode);
        $this->assertDocumentCapability($user, $project, $documentId, 'doc.view', $lang);

        $workspace = $this->drafts->padMaterialWorkspace($project, $user, $documentId);

        return view('documents.pms-pad-materials', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'document' => $workspace['document'],
            'revision' => $workspace['revision'],
            'pads' => $workspace['pads'],
            'jalaliDate' => $this->today($lang),
            'topbarTitle' => $lang === 'fa' ? 'جنس پد در پیش‌نویس PMS' : 'PMS PAD materials',
        ]);
    }

    /** Store a separate Table A-1 material or restore the header-material default. */
    public function updatePadMaterial(
        Request $request,
        string $projectCode,
        string $documentId,
        string $classId,
        string $branchRuleId,
    ): RedirectResponse {
        $lang = $this->lang($request);
        $user = $this->currentActor($request);
        $project = $this->resolveProject($user, $projectCode);
        $this->assertDocumentCapability($user, $project, $documentId, 'doc.issue', $lang);

        abort_unless(Str::isUuid($classId) && Str::isUuid($branchRuleId), 404);
        $data = $this->validatePadMaterialInput($request, $branchRuleId);
        $mode = $data['mode'][$branchRuleId];
        $padMaterial = $mode === 'HEADER'
            ? null
            : ($data['pad_material'][$branchRuleId] ?? []);

        $this->drafts->changePadMaterial(
            $project,
            $user,
            $documentId,
            $classId,
            $branchRuleId,
            $padMaterial,
        );

        return redirect()
            ->route('documents.pms.pad-materials', [
                'projectCode' => $project->code,
                'documentId' => $documentId,
                'lang' => $lang,
            ])
            ->with('status', $lang === 'fa'
                ? 'جنس پد در پیش‌نویس بازنگری ذخیره شد؛ بازنگری‌های منتشرشده تغییر نکردند.'
                : 'PAD material saved to the WIP revision; published revisions were not changed.');
    }

    /**
     * Bind fields to the route's branch-rule ID so a failed form only repopulates
     * the card that was submitted, even when a PMS has many PAD rules.
     *
     * @return array<string, mixed>
     */
    private function validatePadMaterialInput(Request $request, string $branchRuleId): array
    {
        $mode = 'mode.'.$branchRuleId;
        $material = 'pad_material.'.$branchRuleId;

        return $request->validate([
            'mode' => ['required', 'array:'.$branchRuleId],
            $mode => ['required', 'in:HEADER,CUSTOM'],
            'pad_material' => ['sometimes', 'array:'.$branchRuleId],
            $material => ['nullable', 'array:spec,grade,psl,s_mpa_override,p_no,group_no'],
            $material.'.spec' => ['required_if:'.$mode.',CUSTOM', 'nullable', 'string', 'max:80'],
            $material.'.grade' => ['required_if:'.$mode.',CUSTOM', 'nullable', 'string', 'max:80'],
            $material.'.psl' => ['nullable', 'string', 'max:16'],
            $material.'.s_mpa_override' => ['nullable', 'numeric', 'gt:0', 'lt:100000000'],
            $material.'.p_no' => ['nullable', 'string', 'max:8', 'regex:/^[1-9]\d*(?:[A-Z]{1,2})?$/', 'required_with:'.$material.'.s_mpa_override'],
            $material.'.group_no' => ['nullable', 'integer', 'min:1', 'max:10000', 'required_with:'.$material.'.s_mpa_override'],
        ]);
    }

    private function assertDocumentCapability(
        User $user,
        Project $project,
        string $documentId,
        string $capability,
        string $lang,
    ): void {
        abort_unless(Str::isUuid($documentId), 404);
        $document = $this->tenant->runAs(
            $user->company_id,
            fn (): ?Document => Document::where('project_id', $project->id)
                ->whereKey($documentId)->first(),
            $project->id,
        );
        abort_if($document === null, 404);
        abort_unless($document->doc_type === 'PMS' && $document->discipline === 'PIPING', 404);

        $denial = $this->authz->check($user, $capability, $project, [
            'discipline' => $document->discipline,
        ]);
        if ($denial !== null) {
            // Read denials hide documents outside the user's discipline scope;
            // write denials remain explicit like the document register.
            abort($capability === 'doc.view' ? 404 : 403, $denial->message($lang));
        }
    }

    private function currentActor(Request $request): User
    {
        $actor = $request->user();

        return User::with('company.plan')
            ->whereKey($actor->id)
            ->where('company_id', $actor->company_id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function resolveProject(User $user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();
        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || DB::table('project_company')->where('project_id', $project->id)
                ->where('company_id', $user->company_id)->exists();
        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'fa';
    }

    private function today(string $lang): string
    {
        $date = Jalalian::now()->format('Y/m/d');

        return $lang === 'fa'
            ? CalendarUtils::convertNumbers($date)
            : $date;
    }
}
