<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Area;
use App\Models\Equipment;
use App\Models\EquipmentNozzle;
use App\Models\Port;
use App\Models\Project;
use App\Models\User;
use App\Support\Numbering\EquipmentTagPattern;
use App\Support\Numbering\Exceptions\UnknownTokenValueException;
use App\Support\Numbering\NumberEngine;
use App\Support\Numbering\NumberPattern;
use App\Support\Reference\PipeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registering equipment and its nozzles (ADR-010 section 3, phase 3b).
 *
 * TAGS ARE SUPPLIED, NEVER INVENTED (the user's ruling, 2026-09-18)
 * -----------------------------------------------------------------
 * An earlier draft offered an ALLOCATE mode that issued the next sequence.
 * Building it exposed why it is the wrong idea: the counter can only see rows
 * that still EXIST, so deleting `11-P-102` during design handed that tag
 * straight back to the next pump - and a tag that has reached a P&ID, a
 * datasheet or a purchase order must never be reused. Guarding against that
 * would mean an issued-number ledger that the engineering tag list already is.
 *
 * So the system VALIDATES and never allocates:
 *
 *   - the tag is supplied, from the engineering list or an import
 *   - it is checked against the project pattern, and a tag that does not fit
 *     is reported with what was wrong rather than silently normalised
 *   - the full tag, unit code included, must be unique across the PROJECT
 *
 * That last rule is the user's own analysis: `11-P-101A` and `21-P-101A` are
 * different pumps, and a bare `P-101A` is what gets the wrong pump hydrotested.
 */
final class EquipmentService
{
    public function __construct(
        private readonly NumberEngine $engine,
        private readonly PipingLineService $lines,
        private readonly PipeReference $reference,
        private readonly ProjectArchiveService $archives,
    ) {}

    // ---------------------------------------------------------- register --

    /**
     * Register a piece of equipment.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(Project $project, User $actor, array $attributes): Equipment
    {
        $this->archives->assertWritable($project);

        $config = $this->lines->configFor($project);

        $type = $this->resolveType($project, $attributes['equipment_type'] ?? null);
        [$area, $unitCode] = $this->resolveUnit($project, $attributes);

        return DB::transaction(function () use ($project, $actor, $attributes, $config, $type, $area, $unitCode): Equipment {
            // Lock the PROJECT row. Nothing is allocated, but the
            // check-then-insert below is still a race: two concurrent requests
            // carrying the same tag would both find it free. The unique index
            // is the backstop; the lock is what turns a 23505 into a sentence.
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $pattern = $this->pattern($config);

            [$tag, $sequence, $suffix] = $this->validateTag($project, $pattern, $unitCode, $type, $attributes);

            $duplicate = Equipment::query()
                ->where('project_id', $project->id)
                ->where('tag_number', $tag)
                ->exists();

            if ($duplicate) {
                // The unique index would catch this too, but as a 23505. A
                // field error names the tag and the mode that produced it.
                throw ValidationException::withMessages([
                    'tag_number' => __('epcc.equipment.tag_taken', ['tag' => $tag]),
                ]);
            }

            return Equipment::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'subsystem_id' => $attributes['subsystem_id'] ?? null,
                'area_id' => $area?->id,
                'unit_code' => $unitCode,
                'tag_number' => $tag,
                'equipment_type' => $type,
                'tag_sequence' => $sequence,
                'tag_suffix' => $suffix,
                'name_en' => $attributes['name_en'] ?? null,
                'name_fa' => $attributes['name_fa'] ?? null,
                'service_description' => $attributes['service_description'] ?? null,
                'design_code' => $attributes['design_code'] ?? null,
                'material_spec' => $attributes['material_spec'] ?? null,
                'state' => $this->resolveState($attributes),
                'source' => strtoupper((string) ($attributes['source'] ?? 'MANUAL')),
                'external_ref' => $attributes['external_ref'] ?? null,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * The tag is supplied, and must fit the pattern.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    private function validateTag(
        Project $project,
        NumberPattern $pattern,
        string $unitCode,
        string $type,
        array $attributes,
    ): array {
        $tag = trim((string) ($attributes['tag_number'] ?? ''));

        if ($tag === '') {
            throw ValidationException::withMessages([
                'tag_number' => __('epcc.equipment.tag_required'),
            ]);
        }

        // Split a trailing parallel-item suffix before parsing: the pattern
        // describes 11-P-101, and 'A' is an annotation on the sequence.
        $suffix = null;
        $core = $tag;

        if (preg_match('/^(.*?)([A-Za-z])$/', $tag, $m) === 1 && preg_match('/\d$/', $m[1]) === 1) {
            $core = $m[1];
            $suffix = strtoupper($m[2]);
        }

        $parsed = $this->engine->parse($pattern, $core, $this->vocabularies($project));

        if (! $parsed->isValid()) {
            // parse() never throws - an unreadable tag is an ordinary import
            // outcome, and the caller needs the diagnosis, not a stack trace.
            throw ValidationException::withMessages([
                'tag_number' => array_merge(
                    [__('epcc.equipment.tag_malformed', ['tag' => $tag, 'sketch' => $pattern->sketch()])],
                    $parsed->explain(),
                ),
            ]);
        }

        // The tag says which unit and type it belongs to. If the form says
        // something else, one of them is wrong and guessing which would put a
        // pump in the wrong unit's register.
        $tagUnit = $parsed->token('unit');
        $tagType = $parsed->token('type');

        if ($tagUnit !== null && $tagUnit !== $unitCode) {
            throw ValidationException::withMessages([
                'tag_number' => __('epcc.equipment.tag_unit_mismatch', [
                    'tag_unit' => $tagUnit,
                    'unit' => $unitCode,
                ]),
            ]);
        }

        if ($tagType !== null && $tagType !== $type) {
            throw ValidationException::withMessages([
                'tag_number' => __('epcc.equipment.tag_type_mismatch', [
                    'tag_type' => $tagType,
                    'type' => $type,
                ]),
            ]);
        }

        return [$tag, $parsed->sequence(), $suffix];
    }

    // ------------------------------------------------------------ nozzles --

    /**
     * Add a nozzle, and the port through which it connects.
     *
     * The port is created HERE rather than by the caller, for the same reason
     * component ports are: a nozzle without a port is invisible to the joint
     * machinery, and nothing in the API should be able to produce one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addNozzle(Equipment $equipment, User $actor, array $attributes): EquipmentNozzle
    {
        $project = Project::query()->findOrFail($equipment->project_id);
        $this->archives->assertWritable($project);

        $tag = trim((string) ($attributes['tag'] ?? ''));

        if ($tag === '') {
            throw ValidationException::withMessages([
                'tag' => __('epcc.equipment.nozzle_tag_required'),
            ]);
        }

        $size = $this->resolveSize($attributes['nominal_size'] ?? null);

        $type = strtoupper((string) ($attributes['nozzle_type'] ?? 'PROCESS'));

        if (! in_array($type, EquipmentNozzle::TYPES, true)) {
            throw ValidationException::withMessages([
                'nozzle_type' => __('epcc.equipment.unknown_nozzle_type', ['value' => $type]),
            ]);
        }

        $endType = $attributes['end_type'] ?? 'FL';

        if ($endType !== null) {
            try {
                $this->reference->endType((string) $endType);
            } catch (\RuntimeException) {
                throw ValidationException::withMessages([
                    'end_type' => __('epcc.joint.reason.end_unknown_code', ['code' => (string) $endType]),
                ]);
            }
        }

        return DB::transaction(function () use ($equipment, $attributes, $tag, $type, $size, $endType): EquipmentNozzle {
            $duplicate = EquipmentNozzle::query()
                ->where('equipment_id', $equipment->id)
                ->where('tag', $tag)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'tag' => __('epcc.equipment.nozzle_tag_taken', [
                        'tag' => $tag,
                        'equipment' => $equipment->tag_number,
                    ]),
                ]);
            }

            $nozzle = EquipmentNozzle::create([
                'project_id' => $equipment->project_id,
                'company_id' => $equipment->company_id,
                'equipment_id' => $equipment->id,
                'tag' => $tag,
                'nozzle_type' => $type,
                'nominal_size' => $size?->nps,
                'nominal_size_decimal' => $size?->npsDecimal,
                'end_type' => $endType,
                'rating' => $attributes['rating'] ?? null,
                'facing' => $attributes['facing'] ?? null,
                'schedule' => $attributes['schedule'] ?? null,
                'elevation_mm' => $attributes['elevation_mm'] ?? null,
                'orientation' => $attributes['orientation'] ?? null,
                'is_spare' => (bool) ($attributes['is_spare'] ?? false),
                'datasheet_ref' => $attributes['datasheet_ref'] ?? null,
                'external_ref' => $attributes['external_ref'] ?? null,
            ]);

            // The port. owner_kind = NOZZLE, component_id null - the CHECK
            // `ports_exactly_one_owner` makes the mixed state unrepresentable.
            Port::create([
                'project_id' => $equipment->project_id,
                'company_id' => $equipment->company_id,
                'owner_kind' => Port::OWNER_NOZZLE,
                'nozzle_id' => $nozzle->id,
                'seq' => 1,
                'role' => 'NOZZLE',
                'nominal_size' => $size?->nps,
                'nominal_size_decimal' => $size?->npsDecimal,
                'end_type' => $endType,
                // Rating and facing are copied onto the PORT, not left on the
                // nozzle alone: PortCompatibility reads ports, and a rating
                // that lives only on the nozzle would never be checked - the
                // 150#-against-300# error would sail through.
                'rating' => $attributes['rating'] ?? null,
                'facing' => $attributes['facing'] ?? null,
                'schedule' => $attributes['schedule'] ?? null,
                'is_connected' => false,
            ]);

            return $nozzle->fresh(['port']);
        });
    }

    // ----------------------------------------------------------- deleting --

    /**
     * Withdraw a piece of equipment.
     *
     * NOT a DELETE. The row survives, carrying its tag, so the unique index
     * goes on refusing that tag forever (the user's ruling, 2026-09-18). Phase
     * 3b withdrew auto-numbering because `max()` cannot see rows that are gone;
     * hard-deleting reopens the same hole from the other side, letting somebody
     * retype a tag that is stamped on a nameplate and printed on an issued P&ID.
     *
     * Refused while anything is still connected. A vessel whose nozzles are
     * welded to live lines is not a data-entry mistake, and deleting it would
     * leave those lines joined to something that no longer exists. Disconnect
     * first - the same discipline `separate()` imposes on a FITTED joint.
     */
    public function remove(Equipment $equipment, User $actor, string $reason): Equipment
    {
        $project = Project::query()->findOrFail($equipment->project_id);
        $this->archives->assertWritable($project);

        if ($equipment->state === Equipment::STATE_DELETED) {
            throw ValidationException::withMessages([
                'state' => __('epcc.equipment.already_deleted', ['tag' => $equipment->tag_number]),
            ]);
        }

        // A reason is mandatory. "Deleted by someone, at some time, for no
        // recorded cause" is the audit trail that answers nothing, and the
        // question people actually ask six months later is WHY.
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'deletion_reason' => __('epcc.equipment.deletion_reason_required'),
            ]);
        }

        $connected = $equipment->nozzles()
            ->where('is_deleted', false)
            ->with('port')
            ->get()
            ->filter(fn (EquipmentNozzle $n): bool => $n->isConnected());

        if ($connected->isNotEmpty()) {
            throw ValidationException::withMessages([
                'state' => __('epcc.equipment.still_connected', [
                    'tag' => $equipment->tag_number,
                    'nozzles' => $connected->pluck('tag')->implode(', '),
                ]),
            ]);
        }

        return DB::transaction(function () use ($equipment, $actor, $reason): Equipment {
            $now = now();

            // The nozzles go with it. They are parts of this vessel, not
            // independent objects, and leaving them live would keep them in the
            // nozzle schedule of something that has been withdrawn.
            $equipment->nozzles()->where('is_deleted', false)->update([
                'is_deleted' => true,
                'deleted_at' => $now,
                'deleted_by' => $actor->id,
                'deletion_reason' => __('epcc.equipment.deleted_with_parent'),
            ]);

            $equipment->update([
                'state' => Equipment::STATE_DELETED,
                'deleted_at' => $now,
                'deleted_by' => $actor->id,
                'deletion_reason' => $reason,
            ]);

            return $equipment->fresh();
        });
    }

    /**
     * Withdraw a single nozzle.
     *
     * Same rule, one level down: the tag stays reserved on this vessel, and a
     * connected nozzle is refused.
     */
    public function removeNozzle(EquipmentNozzle $nozzle, User $actor, string $reason): EquipmentNozzle
    {
        $project = Project::query()->findOrFail($nozzle->project_id);
        $this->archives->assertWritable($project);

        if ($nozzle->is_deleted) {
            throw ValidationException::withMessages([
                'is_deleted' => __('epcc.equipment.nozzle_already_deleted', ['tag' => $nozzle->tag]),
            ]);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'deletion_reason' => __('epcc.equipment.deletion_reason_required'),
            ]);
        }

        if ($nozzle->isConnected()) {
            throw ValidationException::withMessages([
                'is_deleted' => __('epcc.equipment.nozzle_still_connected', ['tag' => $nozzle->tag]),
            ]);
        }

        $nozzle->update([
            'is_deleted' => true,
            'deleted_at' => now(),
            'deleted_by' => $actor->id,
            'deletion_reason' => $reason,
        ]);

        return $nozzle->fresh();
    }

    // ------------------------------------------------------------ reading --

    /**
     * The nozzle schedule: every nozzle of a vessel and what is attached.
     *
     * The classic deliverable, and the reason `is_spare` exists - an
     * unconnected spare is fine, an unconnected process nozzle before hydrotest
     * is the error worth finding.
     *
     * @return list<array<string, mixed>>
     */
    public function nozzleSchedule(Equipment $equipment): array
    {
        $rows = [];

        foreach ($equipment->nozzles()->where('is_deleted', false)->with('port')->orderBy('tag')->get() as $nozzle) {
            $rows[] = [
                'tag' => $nozzle->tag,
                'type' => $nozzle->nozzle_type,
                'size' => $nozzle->nominal_size,
                'rating' => $nozzle->rating,
                'facing' => $nozzle->facing,
                'end_type' => $nozzle->end_type,
                'is_spare' => $nozzle->is_spare,
                'connected' => $nozzle->isConnected(),
                // An unconnected non-spare nozzle. Named rather than left to
                // the reader to work out from two booleans.
                'outstanding' => ! $nozzle->is_spare && ! $nozzle->isConnected(),
            ];
        }

        return $rows;
    }


    // ------------------------------------------------------------ helpers --

    private function pattern(\App\Models\ProjectPipingConfig $config): NumberPattern
    {
        $raw = $config->equipment_tag_pattern;

        return NumberPattern::fromArray(
            is_array($raw) && $raw !== [] ? $raw : EquipmentTagPattern::default()
        );
    }

    /** @return array<string, list<string>> */
    private function vocabularies(Project $project): array
    {
        $config = $this->lines->configFor($project);

        return [
            'type' => EquipmentTagPattern::typeVocabulary($config->equipment_types),
        ];
    }

    private function resolveType(Project $project, mixed $type): string
    {
        $type = strtoupper(trim((string) $type));

        if ($type === '') {
            throw ValidationException::withMessages([
                'equipment_type' => __('epcc.equipment.type_required'),
            ]);
        }

        $allowed = $this->vocabularies($project)['type'];

        if (! in_array($type, $allowed, true)) {
            // A closed vocabulary is what makes a typo'd 'PP' a refusal rather
            // than a new equipment class nobody meant to create.
            throw ValidationException::withMessages([
                'equipment_type' => __('epcc.equipment.unknown_type', [
                    'value' => $type,
                    'allowed' => implode(', ', $allowed),
                ]),
            ]);
        }

        return $type;
    }

    /**
     * Which unit this equipment is addressed by.
     *
     * The unit code is copied onto the equipment row rather than read through
     * the area every time: the tag was composed from it, and a tag whose unit
     * silently changes when an area is re-parented no longer matches the
     * nameplate.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Area|null, 1: string}
     */
    private function resolveUnit(Project $project, array $attributes): array
    {
        $areaId = $attributes['area_id'] ?? null;

        if ($areaId !== null && $areaId !== '') {
            $area = Area::query()->where('project_id', $project->id)->find($areaId);

            if ($area === null) {
                throw ValidationException::withMessages([
                    'area_id' => __('epcc.equipment.unknown_area'),
                ]);
            }

            return [$area, (string) $area->unit_code];
        }

        // Offsite and utility items legitimately have no area, and the
        // standards handle them with a real unit code ('00', '80', '90')
        // rather than a blank - otherwise common equipment collides with
        // process units in the tag space.
        $unit = trim((string) ($attributes['unit_code'] ?? ''));

        if ($unit === '') {
            throw ValidationException::withMessages([
                'unit_code' => __('epcc.equipment.unit_required'),
            ]);
        }

        return [null, $unit];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveState(array $attributes): string
    {
        $state = strtoupper((string) ($attributes['state'] ?? 'PLANNED'));

        return in_array($state, Equipment::STATES, true) ? $state : 'PLANNED';
    }

    private function resolveSize(mixed $nps): ?\App\Support\Reference\PipeSize
    {
        if ($nps === null || $nps === '') {
            return null;
        }

        $size = $this->reference->resolveLabel((string) $nps);

        if ($size === null) {
            throw ValidationException::withMessages([
                'nominal_size' => __('epcc.component.unknown_size', ['size' => (string) $nps]),
            ]);
        }

        return $size;
    }
}
