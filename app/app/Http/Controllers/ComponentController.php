<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Component;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Services\ComponentService;
use App\Support\Reference\PipeReference;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly \App\Services\PmsPinnedClassSpecification $issuedPms,
        private readonly \App\Services\PipeScheduleExceptionRequestService $scheduleRequests,
        private readonly \App\Services\ProjectArchiveService $archives,
        private readonly \App\Support\AuthorizationPipeline $authz,
        private readonly \App\Services\JointService $joints,
    ) {}

    public function index(Request $request, string $projectCode, string $lineId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$line, $rows, $open, $joints, $metrics] = $this->tenant->runAs(
            $user->company_id,
            function () use ($lineId): array {
                $line = PipingLine::with(['subsystem', 'pipingClass', 'pmsSnapshot.documentRevision.document'])->findOrFail($lineId);

                $rows = Component::with('ports')
                    ->where('piping_line_id', $line->id)
                    ->orderBy('sequence')
                    ->get();

                // Eager-loaded through the ports to their components: the
                // joint register names the two components, and without this
                // the table issues four queries per row.
                $joints = \App\Models\Joint::with(['portA.component', 'portB.component'])
                    ->where('piping_line_id', $line->id)
                    ->orderByRaw('joint_seq NULLS LAST')
                    ->get();

                return [
                    $line,
                    $rows,
                    $this->components->openPorts($line),
                    $joints,
                    $this->joints->lineMetrics($line),
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
                        'matches' => $component->schedule === $expected,
                    ];
                } catch (ValidationException) {
                    // A malformed/uncovered snapshot must not receive a green badge.
                }
            }
        }

        $pendingScheduleRequests = $this->tenant->runAs($user->company_id,
            fn (): array => DB::table('pipe_schedule_exception_requests as r')
                ->leftJoin('pipe_schedule_exception_decisions as d', 'd.request_id', '=', 'r.id')
                ->whereIn('r.component_id', $rows->pluck('id'))
                ->where('r.company_id', $user->company_id)
                ->where('r.pms_class_snapshot_id', $line->pms_class_snapshot_id)
                ->whereNull('d.id')
                ->pluck('r.id', 'r.component_id')->all(),
            $project->id,
        );
        $canCapture = $this->archives->isWritable($project) && $this->tenant->runAs($user->company_id,
            fn (): bool => $this->authz->allows($user, 'engineering.report.capture', $project, ['discipline' => 'PIPING']),
            $project->id,
        );

        return view('piping.model', [
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
            'metrics' => $metrics,
            'diaInch' => fn (\App\Models\Joint $j): ?float => $this->joints->diaInch($j),
            'templates' => $this->components->placeableTemplates(),
            'sizes' => array_map(
                static fn ($s): string => $s->nps,
                $this->reference->sizes(),
            ),
            'editable' => $line->release_state !== \App\Support\LineReleaseState::RELEASED,
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
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:4000']]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lineId, $componentId): void {
                $component = Component::query()->whereKey($componentId)->firstOrFail();
                abort_if($component->piping_line_id !== $lineId, 404);
            }, $project->id);
            $this->scheduleRequests->submit($project, $user, $componentId, $data['reason']);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang, $request))
                ->withInput()->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang, $request));
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
