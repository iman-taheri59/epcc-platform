<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\LineDocument;
use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\Subsystem;
use App\Services\PipingLineService;
use App\Services\PipingTestPackService;
use App\Support\AuthorizationPipeline;
use App\Support\LineReleaseState;
use App\Support\Numbering\Exceptions\UnknownTokenValueException;
use App\Services\UnitPreferenceService;
use App\Support\Units\UnitRegistry;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * The piping line register and its AFC gate (ADR-006 phase 1).
 *
 * Reads are open to every project participant - the contractor needs the line
 * list as much as the employer does. Writes go through PipingLineService,
 * which owns the numbering and the gate rules; this class only resolves who is
 * asking and about what.
 */
final class PipingLineController extends Controller
{
    public function __construct(
        private readonly PipingLineService $lines,
        private readonly PipingTestPackService $testPacks,
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly UnitPreferenceService $unitPrefs,
        private readonly UnitRegistry $units,
    ) {}

    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$rows, $subsystems, $classes, $stats] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project): array {
                $lines = PipingLine::with(['subsystem', 'pipingClass'])
                    ->orderBy('line_number')
                    ->get();

                $stats = [
                    'total' => $lines->count(),
                    'draft' => $lines->where('release_state', LineReleaseState::DRAFT)->count(),
                    'afc' => $lines->where('release_state', LineReleaseState::AFC_CONFIRMED)->count(),
                    'released' => $lines->where('release_state', LineReleaseState::RELEASED)->count(),
                    'external' => $lines->whereNull('sequence')->count(),
                ];

                return [
                    $lines,
                    Subsystem::orderBy('code')->get(),
                    PipingClass::orderBy('code')->get(),
                    $stats,
                ];
            },
            $project->id,
        );

        // Evidence readiness for every DRAFT line, so the register can show
        // WHY a line cannot pass the gate rather than only that it has not.
        $blockers = $this->tenant->runAs(
            $user->company_id,
            function () use ($rows): array {
                $out = [];
                foreach ($rows as $line) {
                    if ($line->release_state !== LineReleaseState::DRAFT) {
                        continue;
                    }
                    $out[$line->id] = [
                        'missing' => $this->lines->missingEvidence($line),
                        'unapproved' => $this->lines->unapprovedEvidence($line),
                    ];
                }

                return $out;
            },
            $project->id,
        );

        $vocab = $this->tenant->runAs(
            $user->company_id,
            fn (): array => $this->lines->vocabularies($project),
            $project->id,
        );

        // Documents that could serve as evidence, plus what is already linked.
        // Loaded once for the whole page rather than per row.
        [$documents, $attached] = $this->tenant->runAs(
            $user->company_id,
            function () use ($rows): array {
                $docs = Document::query()
                    ->with('currentRevision')
                    ->orderBy('number')
                    ->limit(500)
                    ->get();

                $links = LineDocument::query()
                    ->whereIn('piping_line_id', $rows->pluck('id'))
                    ->with('document.currentRevision')
                    ->get()
                    ->groupBy('piping_line_id');

                return [$docs, $links];
            },
            $project->id,
        );

        return view('piping.index', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'rows' => $rows,
            'subsystems' => $subsystems,
            'classes' => $classes,
            'stats' => $stats,
            'blockers' => $blockers,
            'pattern' => $this->lines->configFor($project)->pattern()->sketch(),
            'vocab' => $vocab,
            'documents' => $documents,
            'attached' => $attached,
            'evidenceKinds' => LineDocument::kinds(),
            'requiredKinds' => LineDocument::requiredForAfc(),
            'patternTokens' => $this->lines->configFor($project)->pattern()->tokenNames(),
            'jalaliDate' => $this->today($lang),
            'displayUnits' => $this->unitPrefs->displayUnits($project, $user),
            'unitMenu' => $this->unitMenu($lang),
            'unitsLocked' => $user->company_id !== $project->owner_company_id,
        ]);
    }

    /**
     * Task-first piping workspace.
     *
     * The register remains the system of record for line creation/editing; this
     * view answers the field question first: which lines are ready, blocked or
     * need construction/QA attention today?
     */
    public function workspace(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$lines, $packs, $subsystems] = $this->tenant->runAs(
            $user->company_id,
            fn (): array => [
                PipingLine::with(['subsystem', 'pipingClass', 'testPack'])
                    ->orderBy('line_number')
                    ->get(),
                PipingTestPack::with('subsystem')
                    ->orderBy('code')
                    ->get(),
                Subsystem::orderBy('code')->get(),
            ],
            $project->id,
        );

        $blockers = $this->tenant->runAs(
            $user->company_id,
            function () use ($lines): array {
                $out = [];
                foreach ($lines as $line) {
                    $out[$line->id] = [
                        'missing' => $line->release_state === LineReleaseState::DRAFT ? $this->lines->missingEvidence($line) : [],
                        'unapproved' => $line->release_state === LineReleaseState::DRAFT ? $this->lines->unapprovedEvidence($line) : [],
                    ];
                }

                return $out;
            },
            $project->id,
        );

        $jointStats = $this->tenant->runAs(
            $user->company_id,
            fn () => Joint::query()
                ->select('piping_line_id')
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when state = 'FITTED' then 1 else 0 end) as fitted")
                ->selectRaw("sum(case when compatibility_state = 'INVALID' then 1 else 0 end) as invalid")
                ->selectRaw("sum(case when numbering_policy = 'REQUIRED' and joint_seq is null then 1 else 0 end) as awaiting_number")
                ->groupBy('piping_line_id')
                ->get()
                ->keyBy('piping_line_id')
                ->map(fn ($row): array => [
                    'total' => (int) $row->total,
                    'fitted' => (int) $row->fitted,
                    'invalid' => (int) $row->invalid,
                    'awaiting_number' => (int) $row->awaiting_number,
                ])
                ->all(),
            $project->id,
        );

        $rows = $lines->map(function (PipingLine $line) use ($blockers, $jointStats): array {
            $b = $blockers[$line->id] ?? ['missing' => [], 'unapproved' => []];
            $j = $jointStats[$line->id] ?? ['total' => 0, 'fitted' => 0, 'invalid' => 0, 'awaiting_number' => 0];
            $evidenceReady = $b['missing'] === [] && $b['unapproved'] === [];
            $fitPercent = $j['total'] === 0 ? 0 : (int) round(($j['fitted'] / $j['total']) * 100);
            $isBlocked = ! $evidenceReady || $j['invalid'] > 0 || $j['awaiting_number'] > 0 || $line->sequence === null;

            return [
                'line' => $line,
                'evidence_ready' => $evidenceReady,
                'is_blocked' => $isBlocked,
                'missing' => $b['missing'],
                'unapproved' => $b['unapproved'],
                'joints' => $j,
                'fit_percent' => $fitPercent,
            ];
        })->values();

        $stats = [
            'total' => $rows->count(),
            'ready' => $rows->where('evidence_ready', true)->count(),
            'blocked' => $rows->where('is_blocked', true)->count(),
            'released' => $lines->where('release_state', LineReleaseState::RELEASED)->count(),
            'joints_total' => array_sum(array_column($jointStats, 'total')),
            'joints_fitted' => array_sum(array_column($jointStats, 'fitted')),
        ];

        $packCards = $packs->map(function (PipingTestPack $pack) use ($rows): array {
            $group = $rows->filter(fn (array $row): bool => $row['line']->test_pack_id === $pack->id);
            $total = $group->count();
            $released = $group->filter(fn (array $row): bool => $row['line']->release_state === LineReleaseState::RELEASED)->count();
            $blocked = $group->where('is_blocked', true)->count();

            return [
                'pack' => $pack,
                'code' => $pack->code,
                'total' => $total,
                'released' => $released,
                'blocked' => $blocked,
                'percent' => $total === 0 ? 0 : (int) round(($released / $total) * 100),
            ];
        });

        $unassigned = $rows->filter(fn (array $row): bool => $row['line']->test_pack_id === null);

        return view('piping.workspace', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'rows' => $rows,
            'stats' => $stats,
            'packs' => $packCards,
            'allPacks' => $packs,
            'subsystems' => $subsystems,
            'unassignedCount' => $unassigned->count(),
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Save unit preferences - either the project defaults or the user's own. */
    public function updateUnits(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'scope' => ['required', 'in:project,user'],
            'units' => ['nullable', 'array'],
            'units.*' => ['nullable', 'string', 'max:16'],
        ]);

        $units = array_map(strval(...), $data['units'] ?? []);

        try {
            if ($data['scope'] === 'project') {
                $this->tenant->runAs(
                    $user->company_id,
                    fn () => $this->unitPrefs->setProjectPreferences($project, $user, $units),
                    $project->id,
                );
            } else {
                $this->unitPrefs->setUserPreferences($user, $units);
            }
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lang, $request))
                ->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lang, $request))
            ->with('status', __('epcc.units.saved'));
    }

    /**
     * Units grouped by quantity, labelled in the active language.
     *
     * @return array<string, array{label: string, units: list<array{code: string, label: string}>}>
     */
    private function unitMenu(string $lang): array
    {
        $out = [];

        foreach ($this->units->quantities() as $quantity) {
            $out[$quantity] = [
                'label' => __('epcc.units.quantities.'.$quantity),
                // Only what the user may pick - the engine still converts the
                // rest (stored kPa, imported kg/cm2) but never offers them.
                'units' => array_map(
                    fn (array $unit): array => [
                        'code' => (string) $unit['code'],
                        'label' => $this->units->label((string) $unit['code'], $lang),
                    ],
                    $this->units->selectableUnitsFor($quantity),
                ),
            ];
        }

        return $out;
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'subsystem_id' => ['required', 'uuid'],
            'nominal_size' => ['nullable', 'string', 'max:16'],
            'service_code' => ['nullable', 'string', 'max:16'],
            'piping_class_id' => ['nullable', 'uuid'],
            'coating_code' => ['nullable', 'string', 'max:16'],
            'line_number' => ['nullable', 'string', 'max:128'],
            'from_ref' => ['nullable', 'string', 'max:255'],
            'to_ref' => ['nullable', 'string', 'max:255'],

            // Design conditions. The unit travels WITH the value: a pressure
            // without its unit is not a pressure, and the service refuses to
            // guess one.
            'design_pressure' => ['nullable', 'numeric'],
            'design_pressure_unit' => ['nullable', 'string', 'max:16'],
            'design_temperature' => ['nullable', 'numeric'],
            'design_temperature_unit' => ['nullable', 'string', 'max:16'],
            'operating_pressure' => ['nullable', 'numeric'],
            'operating_pressure_unit' => ['nullable', 'string', 'max:16'],
            'operating_temperature' => ['nullable', 'numeric'],
            'operating_temperature_unit' => ['nullable', 'string', 'max:16'],
            'test_pressure' => ['nullable', 'numeric'],
            'test_pressure_unit' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $user, $project): void {
                $subsystem = Subsystem::findOrFail($data['subsystem_id']);

                // Two doors, deliberately. A supplied number is registered
                // as-is (ADR-006 s10.2 forbids inventing or normalising
                // numbers); an empty field means "allocate one from the
                // project pattern".
                if (($data['line_number'] ?? '') !== '') {
                    $this->lines->register($subsystem, $user, (string) $data['line_number'], $data);
                } else {
                    $this->lines->create($subsystem, $user, $data);
                }
            }, $project->id);
        } catch (ValidationException $e) {
            // An unknown unit or a temperature below absolute zero. Same
            // treatment as a bad token: a form error to correct, not a crash.
            return redirect($this->backTo($projectCode, $lang, $request))
                ->withInput()
                ->withErrors($e->errors());
        } catch (UnknownTokenValueException $e) {
            // The engine is right to refuse: a value outside the project
            // register would put an unrecognisable number on a drawing. But
            // that is the operator picking a value the project has not set up
            // yet, which is a form error to correct - not a crash.
            // Not back(): a form POST carries no referer in every client, and
            // back() would then land on / and burn the flashed error there.
            return redirect($this->backTo($projectCode, $lang, $request))
                ->withInput()
                ->withErrors(['line_number' => $e->getMessage()]);
        }

        return redirect($this->backTo($projectCode, $lang, $request));
    }

    /** Confirm AFC, release, or pull a line back to draft. */
    public function transition(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'to' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $user, $lineId): void {
            $line = PipingLine::findOrFail($lineId);
            $note = $data['note'] ?? null;

            match ($data['to']) {
                LineReleaseState::AFC_CONFIRMED => $this->lines->confirmAfc($line, $user, $note),
                LineReleaseState::RELEASED => $this->lines->release($line, $user, $note),
                LineReleaseState::DRAFT => $this->lines->revertToDraft($line, $user, (string) $note),
                default => abort(422),
            };
        }, $project->id);

        return redirect($this->backTo($projectCode, $lang, $request));
    }

    /** Attach a CDE document as AFC evidence. */
    public function attach(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'document_id' => ['required', 'uuid'],
            'evidence_kind' => ['required', 'string'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $user, $lineId): void {
            $line = PipingLine::findOrFail($lineId);
            $document = Document::findOrFail($data['document_id']);

            $this->lines->attachEvidence($line, $user, $document, (string) $data['evidence_kind']);
        }, $project->id);

        return redirect($this->backTo($projectCode, $lang, $request));
    }

    /** Remove an evidence link that should not have been made. */
    public function detach(Request $request, string $projectCode, string $lineId, string $linkId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->tenant->runAs($user->company_id, function () use ($lineId, $linkId, $user): void {
            $line = PipingLine::findOrFail($lineId);
            $link = LineDocument::findOrFail($linkId);

            $this->lines->detachEvidence($line, $user, $link);
        }, $project->id);

        return redirect($this->backTo($projectCode, $lang, $request));
    }


    /** Create a hydrotest/test pack from the workspace. */
    public function storeTestPack(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'title_fa' => ['nullable', 'string', 'max:255'],
            'subsystem_id' => ['nullable', 'uuid'],
            'test_pressure' => ['nullable', 'numeric'],
            'test_pressure_unit' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->tenant->runAs(
                $user->company_id,
                fn () => $this->testPacks->create($project, $user, $data),
                $project->id,
            );
        } catch (ValidationException $e) {
            return redirect($this->workspaceBackTo($projectCode, $lang, $request))
                ->withInput()
                ->withErrors($e->errors());
        }

        return redirect($this->workspaceBackTo($projectCode, $lang, $request));
    }

    /** Assign or remove one line from a test pack. */
    public function assignTestPack(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'test_pack_id' => ['nullable', 'uuid'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $lineId, $user): void {
                $line = PipingLine::findOrFail($lineId);
                $pack = ($data['test_pack_id'] ?? null) ? PipingTestPack::findOrFail((string) $data['test_pack_id']) : null;
                $this->testPacks->assignLine($line, $user, $pack);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->workspaceBackTo($projectCode, $lang, $request))
                ->withErrors($e->errors());
        }

        return redirect($this->workspaceBackTo($projectCode, $lang, $request));
    }

    /** Move a test pack through DRAFT -> READY -> TESTED -> ACCEPTED. */
    public function transitionTestPack(Request $request, string $projectCode, string $packId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'to' => ['required', 'string'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $packId, $user): void {
                $pack = PipingTestPack::findOrFail($packId);
                $this->testPacks->transition($pack, $user, (string) $data['to']);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->workspaceBackTo($projectCode, $lang, $request))
                ->withErrors($e->errors());
        }

        return redirect($this->workspaceBackTo($projectCode, $lang, $request));
    }

    // ---------------------------------------------------------- internals --

    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        // Company participation AND personal membership, the same two
        // conditions the certificate and punch registers enforce.
        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function backTo(string $projectCode, string $lang, Request $request): string
    {
        $query = ['lang' => $lang];

        if ($request->has('sid')) {
            $query['sid'] = $request->query('sid');
        }

        return "/projects/{$projectCode}/piping?".http_build_query($query);
    }

    private function workspaceBackTo(string $projectCode, string $lang, Request $request): string
    {
        $query = ['lang' => $lang];

        if ($request->has('sid')) {
            $query['sid'] = $request->query('sid');
        }

        return "/projects/{$projectCode}/piping/workspace?".http_build_query($query);
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
