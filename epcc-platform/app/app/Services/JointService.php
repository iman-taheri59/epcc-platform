<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\User;
use App\Support\LineReleaseState;
use App\Support\Piping\PortCompatibility;
use App\Support\Piping\Verdict;
use App\Support\Reference\PipeReference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating, numbering and measuring joints (ADR-006 phase 3).
 *
 * This is where the model stops being a parts list and becomes something a
 * construction team can work against: every connection is now a row with a
 * number, a state and a measurable size.
 *
 * Three things this service owns that nothing else may do:
 *
 *   1. SORTING THE PAIR. The CHECK demands port_a_id < port_b_id. Callers pass
 *      ports in whatever order the UI produced them; sorting happens once,
 *      here, so no caller can get it wrong.
 *   2. ALLOCATING THE NUMBER under a lock on the parent line row - the same
 *      pattern as line sequences and MR/PO numbers.
 *   3. DECIDING WHETHER A NUMBER IS OWED AT ALL. A flanged joint is not an
 *      unnumbered weld, it is a joint that will never have a number, and the
 *      two must not look alike in a report.
 */
final class JointService
{
    public function __construct(
        private readonly PortCompatibility $compatibility,
        private readonly PipeReference $reference,
        private readonly ProjectArchiveService $archives,
    ) {}

    // -------------------------------------------------------------- join --

    /**
     * Join two ports.
     *
     * Called by ComponentService::attach() for the normal modelling gesture,
     * and directly when closing a loop between two components that already
     * exist (a line that comes back on itself, a tie-in to a header).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function join(Port $first, Port $second, User $actor, array $attributes = []): Joint
    {
        [$a, $b] = $this->orderedPair($first, $second);

        $line = $this->lineFor($a, $b);
        $project = Project::query()->findOrFail($line->project_id);

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);

        // Evaluated BEFORE the transaction and before any write: an INVALID
        // pair must not leave a half-connected port behind.
        $verdict = $this->compatibility->evaluate($a, $b);

        if ($verdict->isInvalid()) {
            throw ValidationException::withMessages([
                'port_id' => array_merge(
                    [__('epcc.joint.refused', [
                        'a' => $a->label(),
                        'b' => $b->label(),
                    ])],
                    $verdict->messages(),
                ),
            ]);
        }

        return DB::transaction(function () use ($a, $b, $line, $actor, $attributes, $verdict): Joint {
            // Lock the line: the joint counter is scoped to it, and two
            // concurrent attachments on the same line would otherwise both
            // read the same max().
            PipingLine::query()->whereKey($line->id)->lockForUpdate()->first();

            // Re-read inside the lock. The ports may have been connected by
            // the other tab between the check above and this point, and the
            // Eloquent objects the caller handed us are stale by then - the
            // same trap as state guards reading a stale model.
            $freshA = Port::query()->whereKey($a->id)->first();
            $freshB = Port::query()->whereKey($b->id)->first();

            if ($freshA === null || $freshB === null) {
                throw ValidationException::withMessages([
                    'port_id' => __('epcc.joint.port_missing'),
                ]);
            }

            foreach ([$freshA, $freshB] as $port) {
                if ($port->is_connected) {
                    throw ValidationException::withMessages([
                        'port_id' => __('epcc.component.port_already_connected', ['port' => $port->label()]),
                    ]);
                }
            }

            $numbered = $this->compatibility->isNumbered($freshA, $freshB);

            $joint = Joint::create([
                'project_id' => $line->project_id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $line->id,
                'port_a_id' => $freshA->id,
                'port_b_id' => $freshB->id,
                'joint_seq' => $numbered ? $this->nextSeq($line) : null,
                'numbering_policy' => $numbered
                    ? Joint::NUMBERING_REQUIRED
                    : Joint::NUMBERING_NOT_APPLICABLE,
                'joint_type' => $this->compatibility->jointType($freshA, $freshB),
                'state' => Joint::STATE_MODELLED,

                // Frozen from the ports at creation. A joint accepted at
                // 6"/SCH40 must keep saying so even if a port is later edited.
                'nominal_size' => $freshA->nominal_size,
                'nominal_size_decimal' => $freshA->nominal_size_decimal,
                'schedule' => $freshA->schedule,
                'thickness_mm' => $this->wallFor($freshA),

                'shop_field' => $this->resolveShopField($freshA, $freshB, $attributes),
                'spool_id' => $attributes['spool_id'] ?? null,
                'isometric_sheet_no' => $attributes['isometric_sheet_no'] ?? null,

                'compatibility_state' => $verdict->state,
                'compatibility_notes' => $verdict->toJson(),

                'source' => $this->resolveSource($attributes),
                'external_ref' => $attributes['external_ref'] ?? null,
                'created_by' => $actor->id,
            ]);

            Port::query()->whereIn('id', [$freshA->id, $freshB->id])->update(['is_connected' => true]);

            return $joint->fresh(['portA', 'portB']);
        });
    }

    /**
     * Break a joint, reopening both of its ports.
     *
     * This is the method phase 2 could not have: with no joint row there was
     * nothing recording WHICH port a component had been joined to, so removing
     * a component could only free its own ports and left the neighbour flagged
     * connected with nothing on the other side - invisible to the open-ends
     * worklist and therefore never finished.
     */
    public function separate(Joint $joint, User $actor): void
    {
        $line = PipingLine::query()->findOrFail($joint->piping_line_id);
        $project = Project::query()->findOrFail($line->project_id);

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);

        if ($joint->isFitted()) {
            // Fit-up is a witnessed event. Deleting the joint would delete the
            // record that it happened; the joint must be reverted first, with
            // whatever that costs in paperwork.
            throw ValidationException::withMessages([
                'state' => __('epcc.joint.fitted_not_separable', [
                    'number' => $joint->displayNumber() ?? '-',
                ]),
            ]);
        }

        DB::transaction(function () use ($joint): void {
            $portIds = [$joint->port_a_id, $joint->port_b_id];

            $joint->delete();

            // Reopen only ports that still exist and are not held by ANOTHER
            // joint. The second condition cannot arise today - a unique index
            // stops a port being in two joints - but separate() is also the
            // path an import correction takes, and the check costs one query.
            Port::query()
                ->whereIn('id', $portIds)
                ->whereNotIn('id', Joint::query()->select('port_a_id'))
                ->whereNotIn('id', Joint::query()->select('port_b_id'))
                ->update(['is_connected' => false]);
        });
    }

    /**
     * Every joint hanging off a component, both as A and as B.
     *
     * @return Collection<int, Joint>
     */
    public function jointsTouching(Component $component): Collection
    {
        $portIds = Port::query()->where('component_id', $component->id)->pluck('id')->all();

        if ($portIds === []) {
            return collect();
        }

        return Joint::query()
            ->whereIn('port_a_id', $portIds)
            ->orWhereIn('port_b_id', $portIds)
            ->get();
    }

    // ---------------------------------------------------------- numbering --

    /**
     * Give a number to a joint that is owed one.
     *
     * Separate from join() because an import arrives with numbers already
     * assigned by the fabricator, and because a joint can lose its number when
     * its ends change - a weld that becomes a flanged connection.
     */
    public function assignNumber(Joint $joint, User $actor, ?int $seq = null): Joint
    {
        $line = PipingLine::query()->findOrFail($joint->piping_line_id);
        $this->archives->assertWritable(Project::query()->findOrFail($line->project_id));

        if ($joint->numbering_policy === Joint::NUMBERING_NOT_APPLICABLE) {
            throw ValidationException::withMessages([
                'joint_seq' => __('epcc.joint.not_numberable'),
            ]);
        }

        return DB::transaction(function () use ($joint, $line, $seq): Joint {
            PipingLine::query()->whereKey($line->id)->lockForUpdate()->first();

            $next = $seq ?? $this->nextSeq($line);

            $taken = Joint::query()
                ->where('piping_line_id', $line->id)
                ->where('joint_seq', $next)
                ->whereKeyNot($joint->id)
                ->exists();

            if ($taken) {
                // The DB unique index would catch this too, but as a 23505 the
                // user cannot read. A field error names the number.
                throw ValidationException::withMessages([
                    'joint_seq' => __('epcc.joint.number_taken', ['number' => $next]),
                ]);
            }

            $joint->update(['joint_seq' => $next]);

            return $joint->fresh();
        });
    }

    /**
     * The next free number on a line.
     *
     * max()+1, not count()+1: joints get deleted during modelling, and
     * count()+1 would reissue a number that already appears on a drawing.
     * Numbers are never reused, even when the gap is obvious.
     */
    private function nextSeq(PipingLine $line): int
    {
        return (int) (Joint::query()
            ->where('piping_line_id', $line->id)
            ->max('joint_seq') ?? 0) + 1;
    }

    // ------------------------------------------------------------ fit-up --

    /**
     * Record that a joint has been fitted up.
     *
     * Both the fitter and the time are mandatory - the DB CHECK insists too.
     * A state that records who but not when is not auditable, and the gap is
     * only ever noticed during a claim, years later.
     */
    public function markFitted(Joint $joint, User $actor, string $fittedByCode, ?\DateTimeInterface $at = null): Joint
    {
        $line = PipingLine::query()->findOrFail($joint->piping_line_id);
        $this->archives->assertWritable(Project::query()->findOrFail($line->project_id));

        $code = trim($fittedByCode);

        if ($code === '') {
            throw ValidationException::withMessages([
                'fitted_by_code' => __('epcc.joint.fitter_required'),
            ]);
        }

        if ($joint->compatibility_state === Verdict::INVALID) {
            // Unreachable through join(), which refuses INVALID outright, but
            // reachable through an import that recorded the verdict rather
            // than rejecting the row.
            throw ValidationException::withMessages([
                'state' => __('epcc.joint.invalid_not_fittable'),
            ]);
        }

        if ($joint->numbering_policy === Joint::NUMBERING_REQUIRED && $joint->joint_seq === null) {
            // A fitted weld with no number cannot be pointed at on a drawing,
            // radiographed against a register, or claimed for.
            throw ValidationException::withMessages([
                'joint_seq' => __('epcc.joint.number_required_before_fitup'),
            ]);
        }

        $joint->update([
            'state' => Joint::STATE_FITTED,
            'fitted_by_code' => $code,
            'fitted_at' => $at ?? now(),
        ]);

        return $joint->fresh();
    }

    // ----------------------------------------------------------- metrics --

    /**
     * Dia-inch of one joint - the SMALLER diameter governs (ADR-010 section 1).
     *
     * Welds only. A flanged joint is real work but it is bolt-up work, and
     * folding it into the welding figure inflates the number the project is
     * paid against.
     */
    public function diaInch(Joint $joint): ?float
    {
        if (! $joint->isWeld()) {
            return null;
        }

        $a = $joint->portA;
        $b = $joint->portB;

        if ($a === null || $b === null) {
            // The ports are gone but the joint row is not - only reachable
            // mid-transaction or in corrupt data. Falling back to the frozen
            // nominal_size would be wrong: that is the size of ONE end, and
            // for a reducing weld the other end may be smaller.
            return null;
        }

        return $this->compatibility->diaInch($a, $b);
    }

    /**
     * Dia-inch totals for a line.
     *
     * Computed live here. This is correct at line scale - tens to hundreds of
     * joints - and must NOT be copied for subsystem or project roll-ups: a
     * full-scan aggregate under RLS measured 11x slower (ADR-006 section 6),
     * so those have to be incrementally maintained counters.
     *
     * @return array{welds: int, fitted: int, dia_inch: float, dia_inch_fitted: float}
     */
    public function lineMetrics(PipingLine $line): array
    {
        $joints = Joint::query()
            ->where('piping_line_id', $line->id)
            ->with(['portA', 'portB'])
            ->get();

        $welds = 0;
        $fitted = 0;
        $dia = 0.0;
        $diaFitted = 0.0;

        foreach ($joints as $joint) {
            $value = $this->diaInch($joint);

            if ($value === null) {
                continue;
            }

            $welds++;
            $dia += $value;

            if ($joint->isFitted()) {
                $fitted++;
                $diaFitted += $value;
            }
        }

        return [
            'welds' => $welds,
            'fitted' => $fitted,
            'dia_inch' => round($dia, 4),
            'dia_inch_fitted' => round($diaFitted, 4),
        ];
    }

    // ------------------------------------------------------------ helpers --

    /**
     * Sort the pair so the CHECK is satisfied, whatever order the caller used.
     *
     * @return array{0: Port, 1: Port}
     */
    private function orderedPair(Port $first, Port $second): array
    {
        if ($first->id === null || $second->id === null) {
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.port_missing'),
            ]);
        }

        return strcmp((string) $first->id, (string) $second->id) < 0
            ? [$first, $second]
            : [$second, $first];
    }

    /**
     * Which line owns this joint.
     *
     * Phase 3b opens the seam phase 3 left: a NOZZLE port has no component and
     * no line of its own. A pipe-to-nozzle joint belongs to the PIPE's line -
     * the vessel does not own it, and the weld appears on the line's isometric
     * and in its dia-inch.
     *
     * A nozzle-to-nozzle joint is refused rather than guessed at: two vessels
     * bolted directly together with no spool between them is not something the
     * model should invent a line for.
     */
    private function lineFor(Port $a, Port $b): PipingLine
    {
        $componentA = $a->component_id === null ? null : Component::query()->find($a->component_id);
        $componentB = $b->component_id === null ? null : Component::query()->find($b->component_id);

        if ($componentA === null && $componentB === null) {
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.both_ends_are_nozzles'),
            ]);
        }

        // One side is a nozzle: the joint belongs to the other side's line.
        if ($componentA === null || $componentB === null) {
            $component = $componentA ?? $componentB;

            return PipingLine::query()->findOrFail($component->piping_line_id);
        }

        if ($componentA->piping_line_id !== $componentB->piping_line_id) {
            // Cross-line joints are real (a tie-in), but they need a decision
            // about which line owns the number before they can be allowed.
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.cross_line_not_supported'),
            ]);
        }

        return PipingLine::query()->findOrFail($componentA->piping_line_id);
    }

    private function wallFor(Port $port): ?float
    {
        if ($port->nominal_size === null || $port->schedule === null) {
            return null;
        }

        try {
            return $this->reference->wall($port->nominal_size, $port->schedule);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Shop or field.
     *
     * Taken from the components when they agree: a joint between two shop
     * items is a shop weld. When they disagree it is a field weld - that is
     * what joining a prefabricated spool to another one means.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolveShopField(Port $a, Port $b, array $attributes): ?string
    {
        $explicit = $attributes['shop_field'] ?? null;

        if ($explicit !== null && $explicit !== '') {
            $value = strtoupper((string) $explicit);

            if (! in_array($value, ['SHOP', 'FIELD'], true)) {
                throw ValidationException::withMessages([
                    'shop_field' => __('epcc.component.unknown_fabrication', ['value' => $value]),
                ]);
            }

            return $value;
        }

        // A nozzle has no fabrication category of its own - equipment is
        // procured, not fabricated on this job. Joining pipe to a vessel
        // nozzle happens in the field by definition: the vessel is set, then
        // the spool is welded to it.
        if ($a->owner_kind === Port::OWNER_NOZZLE || $b->owner_kind === Port::OWNER_NOZZLE) {
            return 'FIELD';
        }

        $fabA = Component::query()->find($a->component_id)?->fabrication;
        $fabB = Component::query()->find($b->component_id)?->fabrication;

        if ($fabA === null || $fabB === null) {
            return null;
        }

        return $fabA === $fabB ? $fabA : 'FIELD';
    }

    /** @param array<string, mixed> $attributes */
    private function resolveSource(array $attributes): string
    {
        $value = strtoupper((string) ($attributes['source'] ?? 'MANUAL'));

        return in_array($value, Component::SOURCES, true) ? $value : 'MANUAL';
    }

    private function assertLineIsModellable(PipingLine $line): void
    {
        if ($line->release_state === LineReleaseState::RELEASED) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.released_not_editable'),
            ]);
        }
    }
}
