<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentNozzle;
use App\Models\Project;
use App\Models\User;
use App\Models\Port;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * ADR-010 §3 — equipment and their nozzles.
 *
 * Equipment exists so a pipe has something real to terminate at. Everything
 * here serves that: a vessel you can tag, nozzles you can point a line at, and
 * a schedule that says which nozzle has a line on it and which one is still
 * open.
 *
 * Scope discipline, and it is deliberate: this is not an equipment-engineering
 * module. No internals, trays, seals, drivers as separate tags, foundations or
 * preservation. None of that is needed for a line to terminate correctly.
 *
 * What this service deliberately does NOT do: it does not decide whether the
 * equipment is fit for service, and it does not check that a nozzle and the
 * flange landing on it are compatible. That check belongs to the same
 * PortCompatibility engine a joint already uses, and wiring it here is the
 * next increment — the one that catches a rating mismatch at model-entry time
 * instead of at bolt-up.
 */
final class EquipmentService
{
    public const TYPES = ['VESSEL', 'PUMP', 'EXCHANGER', 'TANK', 'COMPRESSOR', 'FILTER', 'SKID', 'OTHER'];

    public const STATES = ['PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED'];

    public const NOZZLE_TYPES = ['PROCESS', 'VENT', 'DRAIN', 'MANWAY', 'INSTRUMENT', 'RELIEF', 'SPARE'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly ProjectArchiveService $archives,
        private readonly \App\Support\Reference\PipeReference $reference,
    ) {}

    public function allows(Project $project, User $actor): bool
    {
        return $this->authz->check($actor, 'equipment.maintain', $project) === null;
    }

    /** @return array{equipment: \Illuminate\Support\Collection, can_maintain: bool} */
    public function register(Project $project, User $actor): array
    {
        return [
            'equipment' => Equipment::where('project_id', $project->id)
                ->withCount('nozzles')
                ->orderBy('tag_number')
                ->get(),
            'can_maintain' => $this->allows($project, $actor),
        ];
    }

    public function equipment(Project $project, User $actor, string $equipmentId): array
    {
        $item = Equipment::where('project_id', $project->id)->whereKey($equipmentId)->firstOrFail();

        return [
            'equipment' => $item,
            // The classic deliverable: every nozzle, what it is, and what is
            // attached to it — or that nothing is.
            'schedule' => $this->nozzleSchedule($equipmentId),
            'can_maintain' => $this->allows($project, $actor),
        ];
    }

    /**
     * Every nozzle of a piece of equipment, with the line attached to it.
     *
     * A nozzle with no joint is UNCONNECTED — unless it is a spare, in which
     * case it is capped and saying otherwise would put finished work on the
     * worklist.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nozzleSchedule(string $equipmentId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT n.id, n.tag, n.nozzle_type, n.nps_label, n.dn, n.end_type, n.rating, n.facing,
                   n.is_spare, pl.line_number AS connected_line
              FROM equipment_nozzles n
              LEFT JOIN ports  p       ON p.nozzle_id = n.id
              LEFT JOIN joints j       ON j.port_a_id = p.id OR j.port_b_id = p.id
              LEFT JOIN ports  other   ON other.id = CASE WHEN j.port_a_id = p.id THEN j.port_b_id ELSE j.port_a_id END
              LEFT JOIN components c   ON c.id = other.component_id
              LEFT JOIN piping_lines pl ON pl.id = c.piping_line_id
             WHERE n.equipment_id = ?
             ORDER BY n.tag
        SQL, [$equipmentId]);

        return array_map(static function (object $r): array {
            $spare = (bool) $r->is_spare;

            return [
                'id' => $r->id,
                'tag' => $r->tag,
                'nozzle_type' => $r->nozzle_type,
                'nps_label' => $r->nps_label,
                'dn' => $r->dn,
                'end_type' => $r->end_type,
                'rating' => $r->rating,
                'facing' => $r->facing,
                'is_spare' => $spare,
                'connected_line' => $r->connected_line,
                // Derived, never stored: an open end is unconnected AND not a
                // capped spare. Nothing writes this down.
                'is_open_end' => $r->connected_line === null && ! $spare,
            ];
        }, $rows);
    }

    /** @param  array<string, mixed>  $input */
    public function create(Project $project, User $actor, array $input): Equipment
    {
        return $this->actingAs($actor, $project, function () use ($project, $actor, $input): Equipment {
            $this->assertMayMaintain($project, $actor);

            $data = $this->validated($project, $input);

            return Equipment::create($data + [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'created_by' => $actor->id,
            ]);
        });
    }

    /** @param  array<string, mixed>  $input */
    public function addNozzle(Project $project, User $actor, string $equipmentId, array $input): EquipmentNozzle
    {
        return $this->actingAs($actor, $project, function () use ($project, $actor, $equipmentId, $input): EquipmentNozzle {
            $this->assertMayMaintain($project, $actor);

            // The equipment must be this project's. Without this the nozzle
            // could be grafted onto a vessel it was never cut into.
            Equipment::where('project_id', $project->id)->whereKey($equipmentId)->firstOrFail();

            $data = Validator::make($input, [
                'tag' => ['required', 'string', 'min:2', 'max:32'],
                'nozzle_type' => ['required', 'in:'.implode(',', self::NOZZLE_TYPES)],
                'nps_label' => ['nullable', 'string', 'max:16'],
                'nps_decimal' => ['nullable', 'numeric', 'min:0'],
                'dn' => ['nullable', 'string', 'max:16'],
                'end_type' => ['nullable', 'string', 'max:8'],
                'rating' => ['nullable', 'string', 'max:16'],
                'facing' => ['nullable', 'string', 'max:8'],
                'schedule' => ['nullable', 'string', 'max:16'],
                'is_spare' => ['nullable', 'boolean'],
            ])->validate();

            $data['tag'] = trim($data['tag']);

            $nozzle = EquipmentNozzle::create($data + [
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'equipment_id' => $equipmentId,
                'is_spare' => (bool) ($data['is_spare'] ?? false),
                'created_by' => $actor->id,
            ]);

            $this->declarePort($nozzle, $project, $actor);

            return $nozzle;
        }, $project->id);
    }

    /**
     * Run as this actor, with the actor named in the session as well.
     *
     * The tenant gives us the company and the project; every write policy on
     * `equipment` and `equipment_nozzles` also demands that `created_by` be
     * the actor named in `epcc.result_actor_id`. Without this the INSERT is
     * refused with 42501 and the reason is invisible from the PHP side.
     */
    private function actingAs(User $actor, Project $project, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;

            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$actor->id]);
            try {
                return $fn();
            } finally {
                DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * Give a nozzle the port that makes it a PORT OWNER in fact, not in claim.
     *
     * ADR-010 §3.1 says a nozzle owns a port exactly like a component does —
     * that is what lets a pipe weld to one without touching `joints`. But
     * asserting it in a document does not create the row: until something
     * does, the register shows a nozzle that nothing can be connected to, and
     * the schedule can only ever say UNCONNECTED.
     *
     * Created from the nozzle's own dimensions, in the same breath, so the two
     * cannot drift apart. What is carried is what `ports` can actually hold —
     * size and end type, which is what compatibility and dia-inch read today.
     * Rating and facing stay on the nozzle: `ports` has nowhere to put them,
     * and flange-rating compatibility is a later increment, not this one.
     *
     * A spare gets one too. A capped spare is still a physical flange on the
     * vessel; it will simply never be welded. The schedule distinguishes the
     * two, which is the distinction §3 exists to protect.
     */
    private function declarePort(EquipmentNozzle $nozzle, Project $project, User $actor): void
    {
        // `ports_size_pair_complete` wants the label and the decimal together
        // or not at all, and the nozzle form asks only for the label. So the
        // decimal is derived from the label through the reference pack rather
        // than required — otherwise declaring "6\"" would be refused with a
        // database error about a column the person never saw.
        $label = $nozzle->nps_label;
        $decimal = $nozzle->nps_decimal !== null ? (float) $nozzle->nps_decimal : null;

        if ($label !== null && $decimal === null) {
            $decimal = $this->reference->resolveLabel($label)?->npsDecimal;
        }
        if ($label !== null && $decimal === null) {
            // An unrecognised label keeps the port sizeable later but must not
            // half-fill the pair now.
            $label = null;
        }

        Port::create([
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'company_id' => $actor->company_id,
            'owner_kind' => 'NOZZLE',
            'nozzle_id' => $nozzle->id,
            'seq' => 1,
            'role' => 'END',
            'nominal_size' => $label,
            'nominal_size_decimal' => $decimal,
            'end_type' => $nozzle->end_type,
            'schedule' => $nozzle->schedule,
            'is_connected' => false,
        ]);
    }

    private function assertMayMaintain(Project $project, User $actor): void
    {
        $this->archives->assertWritable($project);
        abort_unless($this->allows($project, $actor), 403);
    }

    /** @param  array<string, mixed>  $input  @return array<string, mixed> */
    private function validated(Project $project, array $input): array
    {
        // Trim BEFORE required/min validation: an all-whitespace value must
        // produce a field error, never a database CHECK error.
        foreach (['tag_number', 'name_en', 'name_fa'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $data = Validator::make($input, [
            'tag_number' => ['required', 'string', 'min:2', 'max:64'],
            'equipment_type' => ['required', 'in:'.implode(',', self::TYPES)],
            'name_en' => ['required', 'string', 'min:2', 'max:200'],
            'name_fa' => ['required', 'string', 'min:2', 'max:200'],
            'service_description' => ['nullable', 'string', 'max:2000'],
            'subsystem_id' => ['nullable', 'uuid'],
            'state' => ['nullable', 'in:'.implode(',', self::STATES)],
            'design_pressure' => ['nullable', 'string', 'max:32'],
            'design_temperature' => ['nullable', 'string', 'max:32'],
            'design_code' => ['nullable', 'string', 'max:64'],
            'external_ref' => ['nullable', 'string', 'max:200'],
        ])->validate();

        // The subsystem must be one of this project's, or a vessel would join
        // two tenants' completion machinery.
        if (($data['subsystem_id'] ?? null) !== null) {
            $known = DB::table('subsystems')
                ->where('project_id', $project->id)
                ->where('id', $data['subsystem_id'])->exists();
            abort_unless($known, 422, __('equipment.subsystem_not_found'));
        }

        return $data;
    }
}
