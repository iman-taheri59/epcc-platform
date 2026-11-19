<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BranchPad;
use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\User;
use App\Support\LineReleaseState;
use App\Support\Reference\PipeReference;
use App\Support\Reference\PipeSize;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Placing components on a line, and the ports that come with them
 * (ADR-006 phase 2, ADR-009 §2).
 *
 * The user's question was: how does the system know a pipe has two ports and a
 * tee has three? It never asks. The component template declares the ports, the
 * service creates them, and nothing in the API accepts a port count.
 *
 * The interaction this exists to support is "pick a component type, pick an
 * open port to attach it to" - three clicks, no port arithmetic. Everything
 * else is derived: the port count from the template, the connecting port's
 * size from its parent, the remaining sizes from the template's size rule.
 *
 * Joints are phase 3. `attach()` here records the structural consequence (the
 * ports are no longer open, the sizes agree) but does not yet create a joint
 * row - that is deliberate, and the seam is `markConnected()`.
 */
final class ComponentService
{
    public function __construct(
        private readonly PipeReference $reference,
        private readonly ProjectArchiveService $archives,
        private readonly JointService $joints,
        private readonly PmsPinnedClassSpecification $issuedPms,
    ) {}

    // ------------------------------------------------------------ create --

    /**
     * Place a component on a line, creating its ports from the template.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function place(PipingLine $line, User $actor, string $templateCode, array $attributes = []): Component
    {
        return $this->placeFromTemplate($line, $actor, $templateCode, $attributes, allowBranchPad: false);
    }

    /**
     * Internal construction path used only by BranchPadService. A pad must be
     * born together with its two wall-fillets and PMS snapshot; exposing it in
     * the ordinary "place a component" flow would allow an unconnected plate
     * with no governing header or weld records.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function placeBranchPad(PipingLine $line, User $actor, array $attributes): Component
    {
        return $this->placeFromTemplate($line, $actor, 'BRANCH_PAD', $attributes, allowBranchPad: true);
    }

    /** @param array<string, mixed> $attributes */
    private function placeFromTemplate(
        PipingLine $line,
        User $actor,
        string $templateCode,
        array $attributes,
        bool $allowBranchPad,
    ): Component {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);

        $template = $this->template($templateCode);

        if ($template['code'] === 'BRANCH_PAD' && ! $allowBranchPad) {
            throw ValidationException::withMessages([
                'template_code' => 'A branch pad must be created from a branch wall root so its two fillet joints and header-class thickness are recorded together.',
            ]);
        }

        return DB::transaction(function () use ($line, $actor, $template, $attributes, $project): Component {
            // Lock the LINE row: the sequence counter is scoped to it. Same
            // reasoning as PipingLineService locking the subsystem - locking
            // the wrong row is how two concurrent placements collide.
            PipingLine::query()->whereKey($line->id)->lockForUpdate()->first();

            $sequence = (int) (Component::query()
                ->where('piping_line_id', $line->id)
                ->max('sequence') ?? 0) + 1;

            $size = $this->resolveSize($attributes['nominal_size'] ?? $line->nominal_size);
            $fittingSpecification = $this->resolveFittingSpecification($template, $attributes);
            if (($fittingSpecification['endType'] ?? null) !== null) {
                // The B16.11 geometry slice is explicitly two-ended and does
                // not infer or mix end preparations. Store the selected code
                // on each generated port, not as a component/PMS class.
                $attributes['end_type'] = $fittingSpecification['endType'];
            }

            // A pipe with no entered schedule inherits the LINE'S ISSUED PMS
            // specification, not the mutable/current piping-class rules.
            // Explicit schedules remain a separate modelling override for now.
            $schedule = $attributes['schedule'] ?? null;
            if ($template['code'] === 'PIPE' && ($schedule === null || $schedule === '')
                && $size !== null && $line->pms_class_snapshot_id !== null) {
                $schedule = $this->issuedPms->pipeSchedule($line, $size->dn);
            }

            $component = Component::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $line->id,
                'template_code' => $template['code'],
                'fitting_qualifier' => $fittingSpecification['qualifier'],
                'fitting_standard' => $fittingSpecification['standard'],
                'fitting_class_designation' => $fittingSpecification['classDesignation'],
                'sequence' => $sequence,
                'nominal_size' => $size?->nps,
                'nominal_size_decimal' => $size?->npsDecimal,
                'schedule' => $schedule,
                'material_code' => $attributes['material_code'] ?? null,
                'length_mm' => $this->resolveLength($template, $attributes),
                'fabrication' => $this->resolveFabrication($attributes),
                'tag' => $attributes['tag'] ?? null,
                'remark' => $attributes['remark'] ?? null,
                'source' => $this->resolveSource($attributes),
                'external_ref' => $attributes['external_ref'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->createPortsFromTemplate($component, $template, $attributes);

            return $component->fresh(['ports']);
        });
    }

    /**
     * Create one port per template port, sized by the template's size rule.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $attributes
     */
    private function createPortsFromTemplate(Component $component, array $template, array $attributes): void
    {
        // Per-port size overrides, keyed by seq. Used by a reducer (6x4) and a
        // reducing tee (6x6x2); ignored entirely by SAME_AS_FIRST components.
        $overrides = [];
        foreach ((array) ($attributes['port_sizes'] ?? []) as $seq => $nps) {
            $overrides[(int) $seq] = $nps;
        }

        $rule = (string) ($template['sizeRule'] ?? 'SAME_AS_FIRST');
        $base = $component->nominal_size;

        foreach ((array) $template['ports'] as $portDef) {
            $seq = (int) $portDef['seq'];

            $nps = match ($rule) {
                // One size for the whole item. An override on port 2 of a
                // valve is a user error, not a 6x4 valve, so it is ignored
                // rather than honoured.
                'SAME_AS_FIRST' => $base,
                default => $overrides[$seq] ?? $base,
            };

            $size = $this->resolveSize($nps);

            Port::create([
                'project_id' => $component->project_id,
                'company_id' => $component->company_id,
                'owner_kind' => Port::OWNER_COMPONENT,
                'component_id' => $component->id,
                'seq' => $seq,
                'role' => (string) $portDef['role'],
                'nominal_size' => $size?->nps,
                'nominal_size_decimal' => $size?->npsDecimal,
                'end_type' => $attributes['end_type'] ?? ($portDef['defaultEnd'] ?? null),
                'schedule' => $component->schedule,
                'is_connected' => false,
            ]);
        }

        $this->assertSizeRule($component->fresh(['ports']), $template);
    }

    /**
     * Enforce the template's size rule across the finished set of ports.
     *
     * Checked AFTER creation rather than per port, because the rules are
     * relational: "the branch is not larger than the run" cannot be judged
     * while only the branch exists.
     *
     * @param  array<string, mixed>  $template
     */
    private function assertSizeRule(Component $component, array $template): void
    {
        $rule = (string) ($template['sizeRule'] ?? 'SAME_AS_FIRST');
        $ports = $component->ports;

        $sizeOf = static fn (string $role): ?float => $ports
            ->first(fn (Port $p): bool => $p->role === $role)?->nominal_size_decimal;

        if ($rule === 'BRANCH_LE_RUN') {
            $run = $sizeOf('RUN');
            $branch = $sizeOf('BRANCH');

            if ($run !== null && $branch !== null && $branch > $run) {
                throw ValidationException::withMessages([
                    'port_sizes' => __('epcc.component.branch_exceeds_run', [
                        'branch' => $this->trimNumber($branch),
                        'run' => $this->trimNumber($run),
                    ]),
                ]);
            }
        }

        if ($rule === 'LARGE_GT_SMALL') {
            $large = $sizeOf('LARGE');
            $small = $sizeOf('SMALL');

            if ($large !== null && $small !== null && $large <= $small) {
                // A reducer whose ends are equal is not a reducer. Catching it
                // here stops a 6x6 "reducer" silently becoming a spool nobody
                // ordered.
                throw ValidationException::withMessages([
                    'port_sizes' => __('epcc.component.reducer_not_reducing', [
                        'large' => $this->trimNumber($large),
                        'small' => $this->trimNumber($small),
                    ]),
                ]);
            }
        }
    }

    // ------------------------------------------------------------ attach --

    /**
     * Attach a new component to an existing open port.
     *
     * This is the modelling gesture. The new component inherits the size of
     * the port it attaches to - it is never typed, which is what makes "6in
     * pipe onto a 2in branch" structurally impossible rather than merely
     * discouraged.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{component: Component, parentPort: Port, childPort: Port, joint: Joint}
     */
    public function attach(Port $port, User $actor, string $templateCode, array $attributes = []): array
    {
        $component = Component::query()->findOrFail($port->component_id);
        $line = PipingLine::query()->findOrFail($component->piping_line_id);
        $project = Project::query()->findOrFail($line->project_id);

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);

        if ($port->is_connected) {
            // The UI never offers a connected port, so reaching here means a
            // stale form or a second tab. Refuse loudly rather than silently
            // creating a second thing joined to the same end.
            throw ValidationException::withMessages([
                'port_id' => __('epcc.component.port_already_connected', ['port' => $port->label()]),
            ]);
        }

        return DB::transaction(function () use ($port, $actor, $templateCode, $attributes, $line): array {
            // Size is inherited from the parent port, and an attempt to
            // override it is discarded rather than honoured.
            $attributes['nominal_size'] = $port->nominal_size;

            $child = $this->place($line, $actor, $templateCode, $attributes);

            $childPort = $child->ports
                ->first(fn (Port $p): bool => (int) $p->seq === (int) ($attributes['child_port_seq'] ?? 1));

            if ($childPort === null) {
                throw ValidationException::withMessages([
                    'child_port_seq' => __('epcc.component.no_such_port'),
                ]);
            }

            // Phase 3: the attachment IS a joint. Creating it here rather
            // than flipping is_connected means the compatibility verdict, the
            // joint number and the dia-inch all exist from the moment the
            // connection does - there is no window in which the model has a
            // connection the joint register has never heard of.
            $joint = $this->joints->join($port, $childPort, $actor, $attributes);

            return [
                'component' => $child->fresh(['ports']),
                'parentPort' => $port->fresh(),
                'childPort' => $childPort->fresh(),
                'joint' => $joint,
            ];
        });
    }

    /**
     * Detach a component, freeing the ports it was joined to.
     *
     * Deleting a component without this leaves its neighbour flagged connected
     * with nothing on the other side - a port that is invisible to the open-ends
     * worklist and therefore never gets finished.
     */
    public function remove(Component $component, User $actor): void
    {
        $line = PipingLine::query()->findOrFail($component->piping_line_id);
        $project = Project::query()->findOrFail($line->project_id);

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);

        if ($component->template_code !== 'BRANCH_PAD'
            && BranchPad::query()->where(function ($query) use ($component): void {
                $query->where('header_pipe_component_id', $component->id)
                    ->orWhere('branch_pipe_component_id', $component->id);
            })->exists()) {
            throw ValidationException::withMessages([
                'component_id' => 'Remove the branch reinforcement pad and its two fillet joints before removing either connected pipe.',
            ]);
        }

        DB::transaction(function () use ($component, $actor): void {
            if ($component->template_code === 'BRANCH_PAD') {
                $pad = BranchPad::query()->where('component_id', $component->id)->firstOrFail();
                $this->joints->removeBranchPad($pad, $actor);
            }

            // Phase 3 closes the hole phase 2 documented. Every joint touching
            // this component is separated FIRST, which reopens the neighbour's
            // port; only then do this component's own ports go. Deleting the
            // ports first would cascade the joints away and leave the
            // neighbour flagged connected with nothing attached - a port
            // invisible to the open-ends worklist and therefore never
            // finished.
            foreach ($this->joints->jointsTouching($component) as $joint) {
                $this->joints->separate($joint, $actor);
            }

            Port::query()->where('component_id', $component->id)->delete();
            $component->delete();
        });
    }

    // ------------------------------------------------------------ reading --

    /**
     * Every port on a line with nothing attached - the modelling worklist.
     *
     * ADR-009 §2.3 calls this the single most useful query in the system: it
     * drives the worklist, the progress metric and the import validator.
     *
     * @return Collection<int, Port>
     */
    public function openPorts(PipingLine $line): Collection
    {
        return Port::query()
            ->where('is_connected', false)
            ->whereIn('component_id', Component::query()
                ->where('piping_line_id', $line->id)
                ->select('id'))
            ->with('component')
            ->get()
            // Sorted in SQL-free PHP, but with an explicit composite key
            // rather than sortBy([...]): the array form compares the two
            // closures as *properties* and silently does nothing useful here,
            // which showed up as P2 listed before P1.
            ->sortBy(fn (Port $p): string => sprintf(
                '%06d-%03d',
                (int) $p->component->sequence,
                (int) $p->seq,
            ))
            ->values();
    }

    /**
     * Template codes a user may place, with their port counts.
     *
     * @return list<array{code: string, name: array<string,string>, ports: int, sizeRule: string}>
     */
    public function placeableTemplates(): array
    {
        $out = [];

        foreach ($this->reference->templates() as $t) {
            // A reinforcement pad has a dedicated atomic workflow: ordinary
            // placement would leave its two wall fillets and class thickness
            // unrepresented.
            if (($t['code'] ?? null) === 'BRANCH_PAD') {
                continue;
            }

            // NOZZLE belongs to equipment, not to a line. It is in the pack
            // because a nozzle port is still a port (ADR-010 §3), but offering
            // it here would let someone place a bare nozzle mid-line.
            if (($t['ownerKind'] ?? null) === 'NOZZLE') {
                continue;
            }

            $out[] = [
                'code' => (string) $t['code'],
                'name' => (array) $t['name'],
                'ports' => count((array) $t['ports']),
                'sizeRule' => (string) ($t['sizeRule'] ?? 'SAME_AS_FIRST'),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------ helpers --

    /** @return array<string, mixed> */
    private function template(string $code): array
    {
        try {
            $template = $this->reference->template($code);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'template_code' => __('epcc.component.unknown_template', ['code' => $code]),
            ]);
        }

        if (($template['ownerKind'] ?? null) === 'NOZZLE') {
            throw ValidationException::withMessages([
                'template_code' => __('epcc.component.nozzle_not_placeable'),
            ]);
        }

        return $template;
    }

    /**
     * A line that has been released is not a modelling surface any more.
     *
     * Same rule as editing its design conditions: changing what a line is made
     * of after it has been issued for construction is a revision, not an edit.
     */
    private function assertLineIsModellable(PipingLine $line): void
    {
        if ($line->release_state === LineReleaseState::RELEASED) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.released_not_editable'),
            ]);
        }
    }

    private function resolveSize(mixed $nps): ?PipeSize
    {
        if ($nps === null || $nps === '') {
            return null;
        }

        $size = $this->reference->resolveLabel((string) $nps);

        if ($size === null) {
            // An unrecognised size must not become a NULL size: that would
            // quietly produce a component nobody can build.
            throw ValidationException::withMessages([
                'nominal_size' => __('epcc.component.unknown_size', ['size' => (string) $nps]),
            ]);
        }

        return $size;
    }

    /** @param array<string, mixed> $template */
    private function resolveLength(array $template, array $attributes): ?float
    {
        if (($template['hasLength'] ?? false) !== true) {
            // A tee has no length. Accepting one would put a number in a
            // column that no report knows how to interpret.
            return null;
        }

        $length = $attributes['length_mm'] ?? null;

        return $length === null || $length === '' ? null : (float) $length;
    }

    /**
     * Resolve the two deliberately separate elbow specification paths.
     * B16.9's existing LR qualifier remains sufficient for legacy BW elbows;
     * B16.11 requires an explicit standard, angle, dimensional class, and one
     * explicit end type that is copied to both template ports.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $attributes
     * @return array{standard:?string,qualifier:?string,classDesignation:?int,endType:?string}
     */
    private function resolveFittingSpecification(array $template, array $attributes): array
    {
        $templateCode = (string) ($template['code'] ?? '');
        $rawQualifier = $attributes['fitting_qualifier'] ?? null;
        $qualifier = $rawQualifier === null ? '' : strtoupper(trim((string) $rawQualifier));
        $rawStandard = $attributes['fitting_standard'] ?? null;
        $standard = $rawStandard === null || trim((string) $rawStandard) === ''
            ? null
            : strtoupper(trim((string) $rawStandard));
        $rawClass = $attributes['fitting_class_designation'] ?? null;
        $classText = $rawClass === null ? '' : trim((string) $rawClass);
        $classDesignation = null;
        if ($classText !== '') {
            if (preg_match('/^(2000|3000|6000|9000)$/D', $classText) !== 1) {
                throw ValidationException::withMessages([
                    'fitting_class_designation' => __('epcc.component.elbow_class_invalid'),
                ]);
            }
            $classDesignation = (int) $classText;
        }
        $rawEndType = $attributes['fitting_end_type'] ?? null;
        $endType = $rawEndType === null || trim((string) $rawEndType) === ''
            ? null
            : strtoupper(trim((string) $rawEndType));
        $legacyEndType = $attributes['end_type'] ?? null;
        $firstPortEnd = $endType
            ?? ($legacyEndType === null || trim((string) $legacyEndType) === ''
                ? strtoupper((string) ($template['ports'][0]['defaultEnd'] ?? ''))
                : strtoupper(trim((string) $legacyEndType)));

        if ($templateCode === 'TEE') {
            // TEE qualifier is derived (EQUAL vs REDUCING) from port sizes; the straight-tee
            // geometry contract is B16.9 Table 6.1-7 and is valid for equal tees without an
            // explicit qualifier. Allow an explicit EQUAL/STRAIGHT for straight tees and
            // derive otherwise.
            if ($standard !== null && $standard !== 'B16.9') {
                throw ValidationException::withMessages([
                    'fitting_standard' => __('epcc.component.elbow_standard_unsupported'),
                ]);
            }
            if ($classDesignation !== null || $endType !== null) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.fitting_qualifier_not_applicable'),
                ]);
            }
            if ($qualifier !== '' && ! in_array($qualifier, ['EQUAL', 'STRAIGHT', 'REDUCING'], true)) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.elbow_qualifier_unsupported'),
                ]);
            }
            $teeQualifier = $qualifier === 'REDUCING' ? 'REDUCING' : null;
            if ($qualifier === 'STRAIGHT') {
                $teeQualifier = 'EQUAL';
            }

            return [
                'standard' => $standard ?? ($teeQualifier === null || $teeQualifier === 'EQUAL' ? 'B16.9' : null),
                'qualifier' => $teeQualifier,
                'classDesignation' => null,
                'endType' => null,
            ];
        }

        if ($templateCode === 'REDUCER') {
            if ($standard !== null && $standard !== 'B16.9') {
                throw ValidationException::withMessages([
                    'fitting_standard' => __('epcc.component.elbow_standard_unsupported'),
                ]);
            }
            if ($classDesignation !== null || $endType !== null) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.fitting_qualifier_not_applicable'),
                ]);
            }
            if (! in_array($qualifier, ['CONCENTRIC', 'ECCENTRIC'], true)) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.elbow_qualifier_required'),
                ]);
            }

            return ['standard' => 'B16.9', 'qualifier' => $qualifier, 'classDesignation' => null, 'endType' => null];
        }

        if ($templateCode !== 'ELBOW') {
            if ($qualifier !== '' || $standard !== null || $classDesignation !== null || $endType !== null) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.fitting_qualifier_not_applicable'),
                ]);
            }

            return ['standard' => null, 'qualifier' => null, 'classDesignation' => null, 'endType' => null];
        }

        if ($standard !== null && ! in_array($standard, ['B16.9', 'B16.11'], true)) {
            throw ValidationException::withMessages([
                'fitting_standard' => __('epcc.component.elbow_standard_unsupported'),
            ]);
        }

        if ($standard === 'B16.11') {
            if (! in_array($qualifier, ForgedFittingElbowGeometryProfile::SUPPORTED_ANGLES, true)) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.b16_11_angle_required'),
                ]);
            }
            if ($classDesignation === null) {
                throw ValidationException::withMessages([
                    'fitting_class_designation' => __('epcc.component.elbow_class_required'),
                ]);
            }
            if ($endType === null) {
                throw ValidationException::withMessages([
                    'fitting_end_type' => __('epcc.component.b16_11_end_type_required'),
                ]);
            }
            if (! in_array($endType, ['SW', 'SC'], true)) {
                throw ValidationException::withMessages([
                    'fitting_end_type' => __('epcc.component.b16_11_end_type_unsupported'),
                ]);
            }

            return [
                'standard' => 'B16.11',
                'qualifier' => $qualifier,
                'classDesignation' => $classDesignation,
                'endType' => $endType,
            ];
        }

        if ($standard === null
            && ($classDesignation !== null
                || in_array($qualifier, ForgedFittingElbowGeometryProfile::SUPPORTED_ANGLES, true)
                || ($endType !== null && ! in_array($qualifier, ButtWeldElbowGeometryProfile::SUPPORTED_QUALIFIERS, true)))) {
            throw ValidationException::withMessages([
                'fitting_standard' => __('epcc.component.b16_11_standard_required'),
            ]);
        }

        if ($classDesignation !== null) {
            throw ValidationException::withMessages([
                'fitting_class_designation' => __('epcc.component.elbow_class_requires_b16_11'),
            ]);
        }

        if ($standard === 'B16.9') {
            if (! in_array($qualifier, ButtWeldElbowGeometryProfile::SUPPORTED_QUALIFIERS, true)) {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.elbow_qualifier_required'),
                ]);
            }
            if ($firstPortEnd !== 'BW') {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.elbow_qualifier_requires_bw'),
                ]);
            }

            return ['standard' => 'B16.9', 'qualifier' => $qualifier, 'classDesignation' => null, 'endType' => null];
        }

        // A legacy 90LR/45LR value already names the B16.9 geometry path.
        // This preserves existing callers while new UI submissions also store
        // the standard explicitly.
        if (in_array($qualifier, ButtWeldElbowGeometryProfile::SUPPORTED_QUALIFIERS, true)) {
            if ($firstPortEnd !== 'BW') {
                throw ValidationException::withMessages([
                    'fitting_qualifier' => __('epcc.component.elbow_qualifier_requires_bw'),
                ]);
            }

            return ['standard' => 'B16.9', 'qualifier' => $qualifier, 'classDesignation' => null, 'endType' => null];
        }

        if ($qualifier !== '') {
            throw ValidationException::withMessages([
                'fitting_qualifier' => __('epcc.component.elbow_qualifier_unsupported'),
            ]);
        }

        if ($firstPortEnd === 'BW') {
            throw ValidationException::withMessages([
                'fitting_qualifier' => __('epcc.component.elbow_qualifier_required'),
            ]);
        }

        // Other unqualified legacy elbows may still be modelled, but neither
        // B16.9 nor B16.11 geometry is claimed for them.
        return ['standard' => null, 'qualifier' => null, 'classDesignation' => null, 'endType' => null];
    }

    /** @param array<string, mixed> $attributes */
    private function resolveFabrication(array $attributes): ?string
    {
        $value = $attributes['fabrication'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        $value = strtoupper((string) $value);

        if (! in_array($value, Component::FABRICATIONS, true)) {
            throw ValidationException::withMessages([
                'fabrication' => __('epcc.component.unknown_fabrication', ['value' => $value]),
            ]);
        }

        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function resolveSource(array $attributes): string
    {
        $value = strtoupper((string) ($attributes['source'] ?? 'MANUAL'));

        return in_array($value, Component::SOURCES, true) ? $value : 'MANUAL';
    }

    private function trimNumber(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
}
