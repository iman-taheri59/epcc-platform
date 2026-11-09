<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\EquipmentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ADR-010 §3 — the equipment register and the nozzle schedule.
 *
 * Two screens, and they answer the two questions a piping engineer actually
 * asks: what equipment does this project have, and what is attached to each
 * nozzle of it.
 *
 * Scope is held deliberately. This controller models equipment far enough for
 * a pipe to terminate at it. It does not become an equipment-engineering
 * module: no internals, no trays, no seals, no drivers as separate tags, no
 * foundations, no preservation.
 */
final class EquipmentController
{
    public function __construct(
        private readonly EquipmentService $equipment,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request, string $projectCode): View
    {
        [$user, $project] = $this->resolve($request, $projectCode);

        return $this->tenant->runAs($user->company_id, function () use ($project, $user, $request): View {
            $data = $this->equipment->register($project, $user);

            return view('equipment.index', [
                'lang' => $this->lang($request), 'dir' => $this->lang($request) === 'fa' ? 'rtl' : 'ltr',
                'project' => $project,
                'equipment' => $data['equipment'],
                'canMaintain' => $data['can_maintain'],
                'types' => EquipmentService::TYPES,
                'states' => EquipmentService::STATES,
                'subsystems' => $this->subsystems($project),
            ]);
        }, $project->id);
    }

    public function show(Request $request, string $projectCode, string $equipmentId): View
    {
        [$user, $project] = $this->resolve($request, $projectCode);

        return $this->tenant->runAs($user->company_id, function () use ($project, $user, $equipmentId, $request): View {
            $data = $this->equipment->equipment($project, $user, $equipmentId);

            return view('equipment.show', [
                'lang' => $this->lang($request), 'dir' => $this->lang($request) === 'fa' ? 'rtl' : 'ltr',
                'project' => $project,
                'equipment' => $data['equipment'],
                'schedule' => $data['schedule'],
                'canMaintain' => $data['can_maintain'],
                'nozzleTypes' => EquipmentService::NOZZLE_TYPES,
            ]);
        }, $project->id);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        [$user, $project] = $this->resolve($request, $projectCode);
        $data = $request->validate($this->rules());

        try {
            $this->tenant->runAs($user->company_id, fn () => $this->equipment->create($project, $user, $data), $project->id);
        } catch (ValidationException $e) {
            return redirect($this->back($projectCode))->withInput()->withErrors($e->errors());
        }

        return redirect($this->back($projectCode))->with('status', __('equipment.created'));
    }

    public function storeNozzle(Request $request, string $projectCode, string $equipmentId): RedirectResponse
    {
        [$user, $project] = $this->resolve($request, $projectCode);
        $data = $request->validate([
            'tag' => ['required', 'string', 'min:2', 'max:32'],
            'nozzle_type' => ['required', 'in:'.implode(',', EquipmentService::NOZZLE_TYPES)],
            'nps_label' => ['nullable', 'string', 'max:16'],
            'nps_decimal' => ['nullable', 'numeric', 'min:0'],
            'dn' => ['nullable', 'string', 'max:16'],
            'end_type' => ['nullable', 'string', 'max:8'],
            'rating' => ['nullable', 'string', 'max:16'],
            'facing' => ['nullable', 'string', 'max:8'],
            'schedule' => ['nullable', 'string', 'max:16'],
            'is_spare' => ['nullable', 'boolean'],
        ]);

        try {
            $this->tenant->runAs($user->company_id,
                fn () => $this->equipment->addNozzle($project, $user, $equipmentId, $data),
                $project->id);
        } catch (ValidationException $e) {
            return redirect($this->back($projectCode, $equipmentId))->withInput()->withErrors($e->errors());
        }

        return redirect($this->back($projectCode, $equipmentId))->with('status', __('equipment.nozzle_added'));
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'tag_number' => ['required', 'string', 'min:2', 'max:64'],
            'equipment_type' => ['required', 'in:'.implode(',', EquipmentService::TYPES)],
            'name_en' => ['required', 'string', 'min:2', 'max:200'],
            'name_fa' => ['required', 'string', 'min:2', 'max:200'],
            'service_description' => ['nullable', 'string', 'max:2000'],
            'subsystem_id' => ['nullable', 'uuid'],
            'state' => ['nullable', 'in:'.implode(',', EquipmentService::STATES)],
            'design_pressure' => ['nullable', 'string', 'max:32'],
            'design_temperature' => ['nullable', 'string', 'max:32'],
            'design_code' => ['nullable', 'string', 'max:64'],
            'external_ref' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array{0: \App\Models\User, 1: Project} */
    private function resolve(Request $request, string $projectCode): array
    {
        $user = $request->user();
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return [$user, $project];
    }

    private function back(string $projectCode, ?string $equipmentId = null): string
    {
        $base = '/projects/'.$projectCode.'/equipment';

        return $equipmentId === null ? $base : $base.'/'.$equipmentId;
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'fa' ? 'fa' : 'en';
    }

    private function subsystems(Project $project): \Illuminate\Support\Collection
    {
        return \App\Models\Subsystem::where('project_id', $project->id)->orderBy('code')->get(['id', 'code']);
    }
}
