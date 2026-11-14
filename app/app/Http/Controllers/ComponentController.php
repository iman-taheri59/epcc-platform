<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BranchPad;
use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Services\BranchPadService;
use App\Services\ComponentService;
use App\Services\JointService;
use App\Services\PipeScheduleExceptionRequestService;
use App\Services\PipeScheduleReportService;
use App\Services\PmsPinnedClassSpecification;
use App\Services\ProjectArchiveService;
use App\Support\AuthorizationPipeline;
use App\Support\LineReleaseState;
use App\Support\Reference\PipeReference;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The modelling screen for one line (ADR-006 phase 2, ADR-009 §2.3).
 *
 * Deliberately a separate page from the line register. The register answers
 * "which lines exist and can they be released"; this answers "what is this one
 * line made of" - a different question, a different working session, and a
 * table of components hanging under every row would make the register
 * unreadable.
 *
 * All engineering rules live in ComponentService. This resolves who is asking,
 * about which line, and turns refusals into field errors.
 */
final class ComponentController extends Controller
{
    public function __construct(
        private readonly ComponentService $components,
        private readonly TenantContext $tenant,
        private readonly PipeReference $reference,
        private readonly PmsPinnedClassSpecification $issuedPms,
        private readonly PipeScheduleExceptionRequestService $scheduleRequests,
        private readonly PipeScheduleReportService $reports,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
        private readonly JointService $joints,
        private readonly BranchPadService $branchPads,
    ) {}

    public function index(Request $request, string $projectCode, string $lineId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$line, $rows, $open, $joints, $metrics, $branchRoots, $branchPadRoots, $branchPads, $wallBranchPorts, $wallOlets, $wallHeaders, $ownerLines] = $this->tenant->runAs(
            $user->company_id,
            function () use ($lineId): array {
                $line = PipingLine::with(['subsystem', 'pipingClass', 'pmsSnapshot.documentRevision.document'])->findOrFail($lineId);

                $rows = Component::with(['ports', 'branchPad'])
                    ->where('piping_line_id', $line->id)
                    ->orderBy('sequence')
                    ->get();

                // Eager-load both real port components and a wall-joint's
                // frozen header component. The joint register can therefore
                // describe P2P/OLET wall welds instead of rendering their
                // other side as an unknown `?`.
                $joints = Joint::with([
                    'line', 'portA.component.line', 'portB.component.line',
                    'branchHeaderComponent.line', 'branchWallComponent.line',
                    'branchHeaderLine', 'branchRootJoint',
                    'branchPad.component.line', 'branchPad.headerPipe.line', 'branchPad.branchPipe.line',
                ])
                    ->where(function ($query) use ($line): void {
                        $query->where('piping_line_id', $line->id)
                            // Show the physical pad's two joints on its branch
                            // model page even though the header owns their
                            // register numbers and PMS basis.
                            ->orWhereHas('branchPad.component', fn ($componentQuery) => $componentQuery->where('piping_line_id', $line->id));
                    })
                    ->orderByRaw('joint_seq NULLS LAST')
                    ->get();

                // A branch-line operator may file ordinary two-port joints
                // under an explicit P2P/OLET wall root. Discover those roots
                // by the physical line of port A, not by the root's selected
                // numbering owner. TEE/CROSS branch ports can choose a header
                // line directly from ownerLines below.
                $branchRoots = Joint::query()
                    ->with(['portA.component', 'branchHeaderComponent.line', 'branchPads'])
                    ->whereNull('port_b_id')
                    ->whereNotNull('branch_header_component_id')
                    ->whereIn('joint_type', [
                        Joint::TYPE_BRANCH_STUB_IN,
                        Joint::TYPE_BRANCH_STUB_ON,
                        Joint::TYPE_OLET_HEADER_WELD,
                    ])
                    ->whereHas('portA.component', fn ($query) => $query->where('piping_line_id', $line->id))
                    ->orderBy('created_at')
                    ->get();
                $branchPadRoots = $branchRoots
                    ->filter(fn (Joint $root): bool => $root->branchPads->isEmpty()
                        && in_array($root->joint_type, [Joint::TYPE_BRANCH_STUB_IN, Joint::TYPE_BRANCH_STUB_ON], true))
                    ->filter(fn (Joint $root): bool => $this->branchPads->isEligibleForPad($root))
                    ->values();
                $branchPads = BranchPad::query()
                    ->with([
                        'component.ports', 'branchRootJoint', 'headerPipe.line', 'branchPipe.line',
                        'headerLine', 'headerJoint', 'branchJoint',
                    ])
                    ->whereHas('component', fn ($query) => $query->where('piping_line_id', $line->id))
                    ->orderBy('created_at')
                    ->get();

                $open = $this->components->openPorts($line);
                $wallBranchPorts = $open
                    ->filter(fn (Port $port): bool => $port->component?->template_code === 'PIPE')
                    ->values();
                $wallOlets = $rows
                    ->filter(fn (Component $component): bool => $component->template_code === 'OLET'
                        && $component->ports->firstWhere('role', 'RUN')?->is_connected === false)
                    ->values();
                $wallHeaders = Component::query()
                    ->with('line')
                    ->where('project_id', $line->project_id)
                    ->where('template_code', 'PIPE')
                    ->whereNotNull('length_mm')
                    ->whereNotNull('nominal_size')
                    ->orderBy('piping_line_id')
                    ->orderBy('sequence')
                    ->get();
                $ownerLines = PipingLine::query()
                    ->with('subsystem')
                    ->where('project_id', $line->project_id)
                    ->where('id', '<>', $line->id)
                    ->where('release_state', '<>', LineReleaseState::RELEASED)
                    ->orderBy('line_number')
                    ->get();

                return [
                    $line,
                    $rows,
                    $open,
                    $joints,
                    $this->joints->lineMetrics($line),
                    $branchRoots,
                    $branchPadRoots,
                    $branchPads,
                    $wallBranchPorts,
                    $wallOlets,
                    $wallHeaders,
                    $ownerLines,
                ];
            },
            $project->id,
        );

        // Read-only comparison against the eagerly loaded ISSUE-TIME snapshot.
        // Missing/corrupt evidence is unknown, never silently marked conforming.
        $scheduleChecks = [];
        $spec = $line->pmsSnapshot?->specification;
        if (is_array($spec)) {
            foreach ($rows as $component) {
                if ($component->template_code !== 'PIPE') {
                    continue;
                }
                $size = $component->nominal_size !== null
                    ? $this->reference->resolveLabel($component->nominal_size) : null;
                if ($size === null) {
                    continue;
                }
                try {
                    $expected = $this->issuedPms->pipeScheduleFromSpecification($spec, $size->dn);
                    $scheduleChecks[$component->id] = [
                        'expected' => $expected,
                        'dn' => $size->dn,
                        'matches' => $component->schedule === $expected,
                        'taper_eligible' => $component->schedule !== null
                            && $this->reference->hasSchedule($size->nps, $expected)
                            && $this->reference->hasSchedule($size->nps, $component->schedule)
                            && $this->reference->wall($size->nps, $component->schedule)
                                > $this->reference->wall($size->nps, $expected),
                    ];
                } catch (ValidationException) {
                    // A malformed/uncovered snapshot must not receive a green badge.
                }
            }
        }

        // All own-company requests remain visible, including old component
        // schedules. A decided row is history, NOT automatically an approval.
        $requestHistory = $this->tenant->runAs($user->company_id,
            fn () => DB::table('pipe_schedule_exception_requests as r')
                ->leftJoin('pipe_schedule_exception_decisions as d', 'd.request_id', '=', 'r.id')
                ->whereIn('r.component_id', $rows->pluck('id'))
                ->where('r.company_id', $user->company_id)
                ->orderByDesc('r.created_at')->orderByDesc('r.id')
                ->get(['r.*', 'd.id as decision_id']),
            $project->id,
        );
        $reportHistory = $this->tenant->runAs($user->company_id,
            fn () => DB::table('pipe_schedule_discrepancy_reports')
                ->whereIn('request_id', $requestHistory->pluck('id'))
                ->where('company_id', $user->company_id)
                ->orderBy('created_at')->get()
                ->groupBy('request_id'),
            $project->id,
        );
        // A still-open request for a FORMER schedule cannot mask a new one.
        $pendingScheduleRequests = [];
        foreach ($rows as $component) {
            $pending = $requestHistory->first(fn ($r): bool => $r->component_id === $component->id && $r->decision_id === null
                && $r->pms_class_snapshot_id === $line->pms_class_snapshot_id
                && $r->nps_at_request !== null
                && $r->nps_at_request === $component->nominal_size
                && isset($scheduleChecks[$component->id])
                && (int) $r->dn_at_request === $scheduleChecks[$component->id]['dn']
                && $r->expected_schedule === $scheduleChecks[$component->id]['expected']
                && $r->actual_schedule === $component->schedule);
            if ($pending !== null) {
                $pendingScheduleRequests[$component->id] = [
                    'id' => $pending->id, 'remedy' => $pending->proposed_remedy,
                ];
            }
        }
        $canCapture = $this->archives->isWritable($project) && $this->tenant->runAs($user->company_id,
            fn (): bool => $this->authz->allows($user, 'engineering.report.capture', $project, ['discipline' => 'PIPING']),
            $project->id,
        );

        return view('piping.model', [
            'requestHistory' => $requestHistory->groupBy('component_id'),
            'reportHistory' => $reportHistory,
            'pendingScheduleRequests' => $pendingScheduleRequests,
            'canCapture' => $canCapture,
            'scheduleChecks' => $scheduleChecks,
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'line' => $line,
            'rows' => $rows,
            'open' => $open,
            'joints' => $joints,
            'branchRoots' => $branchRoots,
            'branchPadRoots' => $branchPadRoots,
            'branchPads' => $branchPads,
            'wallBranchPorts' => $wallBranchPorts,
            'wallOlets' => $wallOlets,
            'wallHeaders' => $wallHeaders,
            'ownerLines' => $ownerLines,
            'metrics' => $metrics,
            'diaInch' => fn (Joint $j): ?float => $this->joints->diaInch($j),
            'templates' => $this->components->placeableTemplates(),
            'sizes' => array_map(
                static fn ($s): string => $s->nps,
                $this->reference->sizes(),
            ),
            'editable' => $line->release_state !== LineReleaseState::RELEASED,
        ]);
    }

    /** Place a component that is not attached to anything yet. */
    public function store(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'template_code' => ['required', 'string', 'max:32'],
            'nominal_size' => ['nullable', 'string', 'max:16'],
            'branch_size' => ['nullable', 'string', 'max:16'],
            'small_size' => ['nullable', 'string', 'max:16'],
            'schedule' => ['nullable', 'string', 'max:16'],
            'length_mm' => ['nullable', 'numeric', 'min:0'],
            'fabrication' => ['nullable', 'string', 'max:16'],
            'tag' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $user, $lineId): void {
                $line = PipingLine::findOrFail($lineId);
                $this->components->place($line, $user, (string) $data['template_code'], $this->attributes($data));
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()
                ->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
    }

    /** Submit a review request, not an engineering approval or TQ/NCR issue. */
    public function requestScheduleReview(Request $request, string $projectCode, string $lineId, string $componentId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
            'proposed_remedy' => ['sometimes', 'in:UNDETERMINED,INTERNAL_TAPER_PROPOSED'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lineId, $componentId): void {
                $component = Component::query()->whereKey($componentId)->firstOrFail();
                abort_if($component->piping_line_id !== $lineId, 404);
            }, $project->id);
            $this->scheduleRequests->submit(
                $project, $user, $componentId, $data['reason'],
                $data['proposed_remedy'] ?? 'UNDETERMINED',
            );
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
    }

    /** Capture provenance; neither mode issues or verifies a TQ/NCR. */
    public function captureReport(Request $request, string $projectCode, string $lineId, string $requestId, string $mode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);
        abort_unless(in_array($mode, ['draft', 'external'], true)
            && Str::isUuid($requestId), 404);
        $data = $request->validate(['kind' => ['required', 'in:TQ,NCR']]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lineId, $requestId, $user): void {
                $belongs = DB::table('pipe_schedule_exception_requests as r')
                    ->join('components as c', 'c.id', '=', 'r.component_id')
                    ->where('r.id', $requestId)
                    ->where('r.company_id', $user->company_id)
                    ->where('c.piping_line_id', $lineId)->exists();
                abort_unless($belongs, 404);
            }, $project->id);
            if ($mode === 'draft') {
                $this->reports->draft($project, $user, $requestId, $data['kind']);
            } else {
                $this->reports->registerExternal($project, $user, $requestId, $data['kind'],
                    $request->only([
                        'report_number', 'report_revision', 'reported_issuer', 'reported_signer',
                        'reported_signer_capacity', 'reported_issued_at', 'reported_signed_at',
                        'external_register_ref',
                    ]));
            }
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request))
            ->with('status', $mode === 'draft'
                ? ($lang === 'fa' ? 'پیش‌نویس داخلی ثبت شد؛ هنوز صادر یا تأیید نشده است.'
                    : 'Internal draft saved; not issued or approved.')
                : ($lang === 'fa' ? 'اطلاعات گزارش بیرونی ثبت شد؛ امضا و گزارش هنوز راستی‌آزمایی نشده‌اند.'
                    : 'External report metadata saved; report and signature not verified.'));
    }

    /** Attach a new component to an open port - the modelling gesture. */
    public function attach(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'port_id' => ['required', 'uuid'],
            'template_code' => ['required', 'string', 'max:32'],
            'branch_size' => ['nullable', 'string', 'max:16'],
            'small_size' => ['nullable', 'string', 'max:16'],
            'length_mm' => ['nullable', 'numeric', 'min:0'],
            'schedule' => ['nullable', 'string', 'max:16'],
            'line_owner' => ['nullable', 'in:BRANCH,HEADER'],
            'branch_root_joint_id' => ['nullable', 'uuid'],
            'header_line_id' => ['nullable', 'uuid'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $user): void {
                $port = Port::findOrFail($data['port_id']);
                $this->components->attach($port, $user, (string) $data['template_code'], $this->attributes($data));
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()
                ->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
    }

    /** Create a branch wall root against a real pipe header. */
    public function attachToWall(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);
        $data = $request->validate([
            'wall_kind' => ['required', 'in:P2P,OLET'],
            'branch_port_id' => ['nullable', 'required_if:wall_kind,P2P', 'uuid'],
            'olet_component_id' => ['nullable', 'required_if:wall_kind,OLET', 'uuid'],
            'header_component_id' => ['required', 'uuid'],
            'branch_kind' => ['nullable', 'required_if:wall_kind,P2P', 'in:STUB_IN,STUB_ON'],
            'station_mm' => ['required', 'numeric', 'min:0'],
            'offset_mm' => ['nullable', 'numeric'],
            'branch_longitudinal_angle_deg' => [
                'nullable', 'required_if:wall_kind,P2P', 'prohibited_if:wall_kind,OLET',
                'numeric', 'between:45,90', 'regex:/^\\d+(?:\\.\\d{1,2})?$/',
            ],
            'line_owner' => ['nullable', 'in:BRANCH,HEADER'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $user, $lineId): void {
                $line = PipingLine::query()->findOrFail($lineId);
                $header = Component::query()->findOrFail($data['header_component_id']);
                $attributes = ['line_owner' => $data['line_owner'] ?? 'BRANCH'];
                $stationMm = (float) $data['station_mm'];
                $offsetMm = (float) ($data['offset_mm'] ?? 0);

                if ($data['wall_kind'] === 'P2P') {
                    $branchPort = Port::query()->with('component')->findOrFail($data['branch_port_id']);

                    if ($branchPort->component?->piping_line_id !== $line->id) {
                        throw ValidationException::withMessages([
                            'branch_port_id' => 'The P2P branch end must belong to the line in this page.',
                        ]);
                    }

                    $this->joints->joinToWall(
                        $branchPort,
                        $header,
                        (string) $data['branch_kind'],
                        $stationMm,
                        $offsetMm,
                        (float) $data['branch_longitudinal_angle_deg'],
                        $user,
                        $attributes,
                    );

                    return;
                }

                $olet = Component::query()->findOrFail($data['olet_component_id']);

                if ($olet->piping_line_id !== $line->id) {
                    throw ValidationException::withMessages([
                        'olet_component_id' => 'The OLET must belong to the line in this page.',
                    ]);
                }

                $this->joints->joinOletToWall(
                    $olet,
                    $header,
                    $stationMm,
                    $offsetMm,
                    $user,
                    $attributes,
                );
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()
                ->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
    }

    /** Install a header-class-governed pad with its two numbered fillet joints. */
    public function installBranchPad(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);
        $data = $request->validate([
            'branch_root_joint_id' => ['required', 'uuid'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $lineId, $user): void {
                $line = PipingLine::query()->findOrFail($lineId);
                $root = Joint::query()->with('portA.component')->whereKey($data['branch_root_joint_id'])->firstOrFail();
                abort_unless($root->portA?->component?->piping_line_id === $line->id, 404);
                $this->branchPads->install($root, $user);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
    }

    public function destroy(Request $request, string $projectCode, string $lineId, string $componentId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($componentId, $lineId, $user): void {
                $component = Component::findOrFail($componentId);

                // Belt and braces against a hand-edited URL: the component has
                // to belong to the line in the path, or one line's form could
                // delete another line's content.
                abort_if($component->piping_line_id !== $lineId, 404);

                $this->components->remove($component, $user);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
    }

    /**
     * Turn flat form fields into the service's attribute shape.
     *
     * The form asks for "branch size" and "small end", which are the words on
     * a datasheet; the service wants port_sizes keyed by port number. Doing the
     * translation here keeps the domain API free of form vocabulary.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = $data;

        // Which port number the "branch" or "small end" box refers to depends
        // on the template: the branch is port 3 on a TEE but port 2 on an OLET,
        // and a REDUCER's small end is port 2. Guessing both would let a
        // branch size land on a reducer's large port.
        $ports = [];
        try {
            $ports = (array) ($this->reference->template((string) ($data['template_code'] ?? ''))['ports'] ?? []);
        } catch (\RuntimeException $e) {
            // Unknown template: let the service produce the proper field error
            // rather than second-guessing it here.
            return $attributes;
        }

        $seqOfRole = static function (string $role) use ($ports): ?int {
            foreach ($ports as $port) {
                if (($port['role'] ?? null) === $role) {
                    return (int) $port['seq'];
                }
            }

            return null;
        };

        $portSizes = [];

        if (($data['branch_size'] ?? '') !== '' && ($seq = $seqOfRole('BRANCH')) !== null) {
            $portSizes[$seq] = $data['branch_size'];
        }

        if (($data['small_size'] ?? '') !== '' && ($seq = $seqOfRole('SMALL')) !== null) {
            $portSizes[$seq] = $data['small_size'];
        }

        if ($portSizes !== []) {
            $attributes['port_sizes'] = $portSizes;
        }

        return $attributes;
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'fa' ? 'fa' : 'en';
    }

    private function backTo(string $projectCode, string $lineId, string $lang, Request $request): string
    {
        $query = ['lang' => $lang];

        if ($request->has('sid')) {
            $query['sid'] = $request->query('sid');
        }

        return "/projects/{$projectCode}/piping/{$lineId}/model?".http_build_query($query);
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
}
