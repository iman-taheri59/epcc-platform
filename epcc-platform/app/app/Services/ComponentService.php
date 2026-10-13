<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\User;
use App\Support\LineReleaseState;
use App\Support\Reference\PipeReference;
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
    ) {}

    // ------------------------------------------------------------ create --

    /**
     * Place a component on a line, creating its ports from the template.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function place(PipingLine $line, User $actor, string $templateCode, array $attributes = []): Component
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);

        $template = $this->template($templateCode);

        return DB::transaction(function () use ($line, $actor, $template, $attributes, $project): Component {
            // Lock the LINE row: the sequence counter is scoped to it. Same
            // reasoning as PipingLineService locking the subsystem - locking
            // the wrong row is how two concurrent placements collide.
            PipingLine::query()->whereKey($line->id)->lockForUpdate()->first();

            $sequence = (int) (Component::query()
                ->where('piping_line_id', $line->id)
                ->max('sequence') ?? 0) + 1;

            $size = $this->resolveSize($attributes['nominal_size'] ?? $line->nominal_size);

            $component = Component::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $line->id,
                'template_code' => $template['code'],
                'sequence' => $sequence,
                'nominal_size' => $size?->nps,
                'nominal_size_decimal' => $size?->npsDecimal,
                'schedule' => $attributes['schedule'] ?? null,
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
     * @return array{component: Component, parentPort: Port, childPort: Port, joint: \App\Models\Joint}
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

        DB::transaction(function () use ($component, $actor): void {
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
     * @return \Illuminate\Support\Collection<int, Port>
     */
    public function openPorts(PipingLine $line): \Illuminate\Support\Collection
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

    private function resolveSize(mixed $nps): ?\App\Support\Reference\PipeSize
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
