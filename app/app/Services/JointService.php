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
use App\Support\Piping\Exceptions\UnknownBranchTemplate;
use App\Support\Piping\JointBranchKind;
use App\Support\Piping\JointBranchRuleState;
use App\Support\Piping\JointLineOwner;
use App\Support\Piping\PortCompatibility;
use App\Support\Piping\Verdict;
use App\Support\Reference\Exceptions\UnknownSizeException;
use App\Support\Reference\PipeReference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        private readonly PmsPinnedClassSpecification $issuedPms,
    ) {}

    // -------------------------------------------------------------- join --

    /**
     * Join two ports.
     *
     * Called by ComponentService::attach() for the normal modelling gesture,
     * and directly when closing a loop between two components that already
     * exist on the same line — a line that comes back on itself.
     *
     * Two connections this does NOT make, both refused on purpose rather than
     * unfinished by accident:
     *
     *   - a TIE-IN, where the two physical ends sit on different lines. A
     *     P2P/OLET wall-root link, or an explicit header-line selection for a
     *     TEE/CROSS BRANCH-port weld, may choose a reporting/number owner for a
     *     joint on one physical line. Neither authorizes a two-line port-to-port
     *     tie-in (ADR-006 §§14–15).
     *   - a BRANCH ON A HEADER WALL. This two-port method does not make one;
     *     `joinToWall()` represents a fitting-less P2P stub and
     *     `joinOletToWall()` represents an OLET's fabricated RUN-side weld,
     *     each with a frozen header reference. ISOGEN resolves a PCF tap by
     *     projection onto the header centreline; this service does not project
     *     PCF geometry. An OLET remains a purchased component; it is never
     *     faked as a fitting-less stub.
     *
     * PCF wall taps are currently reported at read-only intake; no model-writing
     * importer calls the wall-joint path — see PcfAdmission::withUnrepresentable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function join(Port $first, Port $second, User $actor, array $attributes = []): Joint
    {
        [$a, $b] = $this->orderedPair($first, $second);

        // lineFor() validates physical topology: ordinary two-port tie-ins
        // between different piping lines remain refused even when a caller
        // asks to file a branch joint under its header's line number.
        $branchLine = $this->lineFor($a, $b);
        $project = Project::query()->findOrFail($branchLine->project_id);
        $lineOwner = $this->lineOwnerFrom($attributes);
        $branchRootId = $this->branchRootIdFrom($attributes);
        $branchRoot = $this->branchRootForLine($branchRootId, $branchLine);
        $requestedHeaderLineId = $this->headerLineIdFrom($attributes);
        $branchKind = $this->branchKindFor($a, $b);

        if ($requestedHeaderLineId !== null && $lineOwner !== JointLineOwner::HEADER) {
            throw ValidationException::withMessages([
                'header_line_id' => 'Choose HEADER ownership before selecting a header line.',
            ]);
        }

        if ($branchRoot !== null && $requestedHeaderLineId !== null) {
            $rootHeaderLine = $this->headerLineForRoot($branchRoot);

            if ($rootHeaderLine->id !== $requestedHeaderLineId) {
                throw ValidationException::withMessages([
                    'header_line_id' => 'The selected header line must match the selected P2P/OLET wall root.',
                ]);
            }
        }

        if ($lineOwner === JointLineOwner::HEADER) {
            if ($branchRoot !== null) {
                $ownerLine = $this->headerLineForRoot($branchRoot);
            } elseif (in_array($branchKind, [JointBranchKind::TEE, JointBranchKind::CROSS], true)) {
                if ($requestedHeaderLineId === null) {
                    throw ValidationException::withMessages([
                        'header_line_id' => 'Select a header line for this TEE/CROSS BRANCH-port weld.',
                    ]);
                }

                $ownerLine = $this->headerLineById($requestedHeaderLineId, $branchLine);

                if ($ownerLine->id === $branchLine->id) {
                    throw ValidationException::withMessages([
                        'header_line_id' => 'Choose a different line; the current physical line is already the branch-line owner.',
                    ]);
                }
            } else {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'Header ownership requires a P2P/OLET wall root, or a manually selected header line for a TEE/CROSS BRANCH-port weld.',
                ]);
            }
        } else {
            $ownerLine = $branchLine;
        }

        $directHeaderLineId = $lineOwner === JointLineOwner::HEADER && $branchRoot === null
            ? $ownerLine->id
            : null;

        if ($ownerLine->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'line_owner' => 'A branch joint can only be owned by a line in the same project.',
            ]);
        }

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($branchLine);
        $this->assertLineIsModellable($ownerLine);

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

        return DB::transaction(function () use (
            $a, $b, $branchLine, $ownerLine, $branchRootId, $directHeaderLineId, $actor, $attributes, $verdict,
        ): Joint {
            $this->lockLines($branchLine, $ownerLine);

            if ($branchRootId !== null) {
                // Serialize against detaching/deleting the root while this
                // joint is being assigned to its header's register.
                Joint::query()->whereKey($branchRootId)->lockForUpdate()->firstOrFail();
                $this->branchRootForLine($branchRootId, $branchLine);
            }

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
                'project_id' => $branchLine->project_id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $ownerLine->id,
                'port_a_id' => $freshA->id,
                'port_b_id' => $freshB->id,
                'branch_root_joint_id' => $branchRootId,
                'branch_header_line_id' => $directHeaderLineId,
                'joint_seq' => $numbered ? $this->nextSeq($ownerLine) : null,
                'numbering_policy' => $numbered
                    ? Joint::NUMBERING_REQUIRED
                    : Joint::NUMBERING_NOT_APPLICABLE,
                'joint_type' => $this->compatibility->jointType($freshA, $freshB),
                'branch_kind' => $this->branchKindFor($freshA, $freshB),
                ...$this->branchVerdictFor($ownerLine, $freshA, $freshB),
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

            return $joint->fresh(['portA', 'portB', 'branchRootJoint']);
        });
    }

    /**
     * Weld a branch pipe onto the wall of a header — the fitting-less branch.
     *
     * ADR-006 §14 / P-22. There is no second port: a pipe has two ends and no
     * port on its side, so the joint records a FROZEN REFERENCE instead — the
     * header component, a station along it, and an offset from its centreline.
     * The alternative, inventing a port on the header wall, would put a fake
     * end on the header and corrupt openPorts(), which drives the worklist, the
     * progress metric and the import validator.
     *
     * This is the shape the PMS calls P2P, and the shape PCF writes as a tap
     * connection and Plant 3D calls a tap weld. It is also the weld that
     * carries the real cost premium: the header has to be cut, the branch end
     * coped to match it, and the weld follows a saddle rather than a flat
     * girth.
     *
     * Physical topology stays on the branch line. The joint's register/number
     * owner is explicitly selectable: either that branch line or the header
     * line. The issued-class verdict follows that selected owner line; the
     * default is BRANCH.
     *
     * @param  string  $kind  JointBranchKind::STUB_IN or ::STUB_ON
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function joinToWall(
        Port $branchPort,
        Component $header,
        string $kind,
        float $stationMm,
        float $offsetMm,
        User $actor,
        array $attributes = [],
    ): Joint {
        $branchLine = $this->lineFor($branchPort, null);
        $headerLine = PipingLine::query()->findOrFail($header->piping_line_id);
        $project = Project::query()->findOrFail($branchLine->project_id);

        if ($header->template_code !== 'PIPE' || $header->project_id !== $project->id
            || $headerLine->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'header_component_id' => 'A P2P wall joint must reference a pipe header in the same project.',
            ]);
        }

        $lineOwner = $this->lineOwnerFrom($attributes);
        $ownerLine = $lineOwner === JointLineOwner::HEADER ? $headerLine : $branchLine;

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($branchLine);
        $this->assertLineIsModellable($headerLine);

        if (! in_array($kind, [JointBranchKind::STUB_IN, JointBranchKind::STUB_ON], true)) {
            throw ValidationException::withMessages([
                'branch_kind' => 'A P2P wall joint is a stub-in or a stub-on, not a fitting.',
            ]);
        }

        $branchComponent = $branchPort->component;

        if ($branchComponent?->template_code === 'OLET') {
            throw ValidationException::withMessages([
                'branch_kind' => 'An OLET must use joinOletToWall() so its purchased fitting and RUN-side weld remain explicit.',
            ]);
        }

        if ($branchComponent?->template_code !== 'PIPE') {
            throw ValidationException::withMessages([
                'port_id' => 'A P2P wall joint must use an end port on a branch pipe.',
            ]);
        }

        $this->assertWallGeometry($header, $stationMm, $offsetMm);

        $verdict = $this->wallVerdictFor($ownerLine, $header, $branchPort);

        return DB::transaction(function () use (
            $branchPort, $header, $kind, $stationMm, $offsetMm, $branchLine, $headerLine, $ownerLine, $actor, $attributes, $verdict,
        ): Joint {
            $this->lockLines($branchLine, $headerLine);
            $freshHeader = Component::query()->whereKey($header->id)->lockForUpdate()->firstOrFail();

            if ($freshHeader->template_code !== 'PIPE' || $freshHeader->project_id !== $branchLine->project_id
                || $freshHeader->piping_line_id !== $headerLine->id) {
                throw ValidationException::withMessages([
                    'header_component_id' => 'The P2P header changed while the joint was being created.',
                ]);
            }

            $this->assertWallGeometry($freshHeader, $stationMm, $offsetMm);

            $fresh = Port::query()->whereKey($branchPort->id)->first();

            if ($fresh === null) {
                throw ValidationException::withMessages([
                    'port_id' => __('epcc.joint.port_missing'),
                ]);
            }

            if ($fresh->is_connected) {
                throw ValidationException::withMessages([
                    'port_id' => __('epcc.component.port_already_connected', ['port' => $fresh->label()]),
                ]);
            }

            // Numbered when the branch end is weldable; its sequence is
            // allocated on the explicitly selected owner line.
            $numbered = $fresh->end_type !== null
                && (bool) ($this->reference->endType((string) $fresh->end_type)['weldable'] ?? false);

            $joint = Joint::create([
                'project_id' => $branchLine->project_id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $ownerLine->id,
                'port_a_id' => $fresh->id,

                // The other side is a point on a wall, not a port.
                'port_b_id' => null,
                'branch_header_component_id' => $freshHeader->id,
                'branch_root_joint_id' => null,
                'branch_station_mm' => $stationMm,
                'branch_offset_mm' => $offsetMm,

                'joint_seq' => $numbered ? $this->nextSeq($ownerLine) : null,
                'numbering_policy' => $numbered
                    ? Joint::NUMBERING_REQUIRED
                    : Joint::NUMBERING_NOT_APPLICABLE,

                'joint_type' => match ($kind) {
                    JointBranchKind::STUB_IN => Joint::TYPE_BRANCH_STUB_IN,
                    JointBranchKind::STUB_ON => Joint::TYPE_BRANCH_STUB_ON,
                },
                'branch_kind' => $kind,
                ...$verdict,
                'state' => Joint::STATE_MODELLED,

                'nominal_size' => $fresh->nominal_size,
                'nominal_size_decimal' => $fresh->nominal_size_decimal,
                'schedule' => $fresh->schedule,
                'thickness_mm' => $this->wallFor($fresh),

                'shop_field' => $attributes['shop_field'] ?? null,
                'spool_id' => $attributes['spool_id'] ?? null,
                'isometric_sheet_no' => $attributes['isometric_sheet_no'] ?? null,

                'compatibility_state' => Verdict::VALID,
                'compatibility_notes' => null,

                'source' => $this->resolveSource($attributes),
                'external_ref' => $attributes['external_ref'] ?? null,
                'created_by' => $actor->id,
            ]);

            Port::query()->whereKey($fresh->id)->update(['is_connected' => true]);

            return $joint->fresh(['portA', 'branchHeaderComponent']);
        });
    }

    /**
     * Weld an OLET's fabricated RUN/FA port onto a specified header wall point.
     *
     * Unlike `joinToWall()`, this is a fitting connection, not a fitting-less
     * P2P stub. The OLET remains a purchased component; its RUN port is the one
     * real port in the joint and the header remains a frozen component
     * reference, never a synthetic wall port. The OLET remains placed on the
     * branch line; each weld's joint-register owner can be selected separately.
     *
     * The class verdict and `branch_kind=OLET` belong to this header-side branch
     * weld. The separate OLET BRANCH-to-pipe butt weld is an ordinary weld, so
     * the branch metrics count the branch connection once, not twice.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function joinOletToWall(
        Component $olet,
        Component $header,
        float $stationMm,
        float $offsetMm,
        User $actor,
        array $attributes = [],
    ): Joint {
        if ($olet->template_code !== 'OLET') {
            throw ValidationException::withMessages([
                'component_id' => 'The wall-fitting path requires an OLET component.',
            ]);
        }

        if ($header->template_code !== 'PIPE') {
            throw ValidationException::withMessages([
                'header_component_id' => 'An OLET wall connection must reference a pipe component.',
            ]);
        }

        $branchLine = PipingLine::query()->findOrFail($olet->piping_line_id);
        $headerLine = PipingLine::query()->findOrFail($header->piping_line_id);
        $project = Project::query()->findOrFail($branchLine->project_id);
        $lineOwner = $this->lineOwnerFrom($attributes);

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($branchLine);
        $this->assertLineIsModellable($headerLine);

        if ($header->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'header_component_id' => 'The OLET and header must belong to the same project.',
            ]);
        }

        return DB::transaction(function () use (
            $olet, $header, $branchLine, $headerLine, $project, $lineOwner, $stationMm, $offsetMm, $actor, $attributes,
        ): Joint {
            // The selected owner line supplies the sequence. Lock both the
            // physical branch and header lines in stable order first.
            $lineIds = array_values(array_unique([$branchLine->id, $headerLine->id]));
            sort($lineIds, SORT_STRING);

            foreach ($lineIds as $lineId) {
                PipingLine::query()->whereKey($lineId)->lockForUpdate()->firstOrFail();
            }

            $freshOlet = Component::query()->whereKey($olet->id)->lockForUpdate()->firstOrFail();
            $freshHeader = Component::query()->whereKey($header->id)->lockForUpdate()->firstOrFail();
            $line = PipingLine::query()->findOrFail($freshOlet->piping_line_id);
            $headerLine = PipingLine::query()->findOrFail($freshHeader->piping_line_id);

            $this->archives->assertWritable($project);
            $this->assertLineIsModellable($line);
            $this->assertLineIsModellable($headerLine);
            $ownerLine = $lineOwner === JointLineOwner::HEADER ? $headerLine : $line;

            if ($freshOlet->template_code !== 'OLET' || $freshHeader->template_code !== 'PIPE'
                || $freshOlet->project_id !== $project->id || $freshHeader->project_id !== $project->id
                || $freshOlet->piping_line_id !== $line->id || $freshHeader->piping_line_id !== $headerLine->id
                || $headerLine->project_id !== $project->id) {
                throw ValidationException::withMessages([
                    'component_id' => 'The OLET and its header must be valid components in the same project.',
                ]);
            }

            $oletPorts = Port::query()
                ->where('component_id', $freshOlet->id)
                ->whereIn('role', ['RUN', 'BRANCH'])
                ->orderBy('seq')
                ->lockForUpdate()
                ->get();
            $runPort = $oletPorts->firstWhere('role', 'RUN');
            $branchPort = $oletPorts->firstWhere('role', 'BRANCH');

            if ($oletPorts->count() !== 2 || $runPort === null || $branchPort === null) {
                throw ValidationException::withMessages([
                    'component_id' => 'The OLET must have exactly one RUN port and one BRANCH port.',
                ]);
            }

            if ($runPort->end_type !== 'FA') {
                throw ValidationException::withMessages([
                    'port_id' => 'The OLET RUN side must be the fabricated FA end for a header-wall weld.',
                ]);
            }

            if ($runPort->is_connected) {
                throw ValidationException::withMessages([
                    'port_id' => __('epcc.component.port_already_connected', ['port' => $runPort->label()]),
                ]);
            }

            if ($runPort->nominal_size_decimal === null || $freshHeader->nominal_size_decimal === null
                || abs($runPort->nominal_size_decimal - $freshHeader->nominal_size_decimal) > 0.0001) {
                throw ValidationException::withMessages([
                    'header_component_id' => 'The OLET RUN size must match the header pipe size.',
                ]);
            }

            if ($branchPort->nominal_size === null || $branchPort->nominal_size_decimal === null) {
                throw ValidationException::withMessages([
                    'port_id' => 'The OLET BRANCH port must have a known size for class resolution and weld metrics.',
                ]);
            }

            $this->assertWallGeometry($freshHeader, $stationMm, $offsetMm);

            $headerPort = Port::query()
                ->where('component_id', $freshHeader->id)
                ->orderBy('seq')
                ->lockForUpdate()
                ->first();

            if ($headerPort === null) {
                throw ValidationException::withMessages([
                    'header_component_id' => 'The header pipe has no ports.',
                ]);
            }

            $verdict = $this->wallVerdictFor($ownerLine, $freshHeader, $branchPort);
            $numbered = $runPort->end_type !== null
                && (bool) ($this->reference->endType((string) $runPort->end_type)['weldable'] ?? false);

            // Rows created before the OLET wall path classified the OLET's
            // branch-outlet butt weld as the branch connection. Move that
            // observation and its issue-time verdict to this root weld
            // atomically. A fitted legacy joint is immutable and must be
            // corrected through a revision workflow, never rewritten here.
            $legacyOutletJoint = Joint::query()
                ->where(function ($query) use ($branchPort): void {
                    $query->where('port_a_id', $branchPort->id)
                        ->orWhere('port_b_id', $branchPort->id);
                })
                ->lockForUpdate()
                ->first();

            if ($legacyOutletJoint?->branch_kind === JointBranchKind::OLET) {
                if ($legacyOutletJoint->isFitted()) {
                    throw ValidationException::withMessages([
                        'state' => 'A fitted legacy OLET outlet weld cannot be reclassified; use the revision workflow.',
                    ]);
                }

                $legacyOutletJoint->update([
                    'branch_kind' => null,
                    'branch_class_type' => null,
                    'branch_class_p2p_kind' => null,
                    'branch_pad_thickness_mm' => null,
                    'branch_rule_state' => null,
                ]);
            }

            $joint = Joint::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $ownerLine->id,
                'port_a_id' => $runPort->id,
                'port_b_id' => null,
                'branch_header_component_id' => $freshHeader->id,
                'branch_root_joint_id' => null,
                'branch_station_mm' => $stationMm,
                'branch_offset_mm' => $offsetMm,

                'joint_seq' => $numbered ? $this->nextSeq($ownerLine) : null,
                'numbering_policy' => $numbered
                    ? Joint::NUMBERING_REQUIRED
                    : Joint::NUMBERING_NOT_APPLICABLE,
                'joint_type' => Joint::TYPE_OLET_HEADER_WELD,
                'branch_kind' => JointBranchKind::OLET,
                ...$verdict,
                'state' => Joint::STATE_MODELLED,

                // The wall weld's measurable size follows the OLET branch
                // diameter, as it does for the fitting-less wall-joint path.
                'nominal_size' => $branchPort->nominal_size,
                'nominal_size_decimal' => $branchPort->nominal_size_decimal,
                'schedule' => $branchPort->schedule,
                'thickness_mm' => $this->wallFor($branchPort),

                'shop_field' => $this->resolveShopField($runPort, $headerPort, $attributes),
                'spool_id' => $attributes['spool_id'] ?? null,
                'isometric_sheet_no' => $attributes['isometric_sheet_no'] ?? null,

                'compatibility_state' => Verdict::VALID,
                'compatibility_notes' => null,

                'source' => $this->resolveSource($attributes),
                'external_ref' => $attributes['external_ref'] ?? null,
                'created_by' => $actor->id,
            ]);

            Port::query()->whereKey($runPort->id)->update(['is_connected' => true]);

            return $joint->fresh(['portA.component', 'branchHeaderComponent']);
        });
    }

    /**
     * A station and an offset that could actually describe a point on this pipe.
     *
     * Refuses rather than guesses, because a location that cannot be checked is
     * a note, not a design — and the two things this number feeds are the
     * spool drawing and the branch cut length, neither of which tolerates a
     * shrug.
     */
    private function assertWallGeometry(Component $header, float $stationMm, float $offsetMm): void
    {
        $length = $header->length_mm;

        if ($length === null) {
            throw ValidationException::withMessages([
                'branch_station_mm' => 'The header has no length, so a station along it cannot be checked.',
            ]);
        }

        if ($stationMm < 0 || $stationMm > (float) $length) {
            throw ValidationException::withMessages([
                'branch_station_mm' => "The station must lie within the header, 0 to {$length} mm.",
            ]);
        }

        // Beyond this the branch misses the pipe altogether. The bound is the
        // header's outer radius: a stub-in penetrates to the bore and a stub-on
        // saddles the outside, so both land at about the same distance from the
        // centreline.
        $nps = $header->nominal_size;

        if ($nps === null) {
            throw ValidationException::withMessages([
                'branch_offset_mm' => 'The header has no nominal size, so an offset from its centreline cannot be checked.',
            ]);
        }

        $radius = $this->reference->size((string) $nps)->odMm / 2.0;

        if (abs($offsetMm) > $radius) {
            throw ValidationException::withMessages([
                'branch_offset_mm' => "The branch centreline must meet the header; the limit is {$radius} mm from its centreline.",
            ]);
        }
    }

    /**
     * What the class says about a branch onto this header, asked the same way
     * `branchVerdictFor()` asks it for a fitting branch — from the line's
     * ISSUED SNAPSHOT, never the live rules.
     *
     * @return array{branch_class_type: string|null, branch_class_p2p_kind: string|null, branch_pad_thickness_mm: float|null, branch_rule_state: string|null}
     */
    private function wallVerdictFor(PipingLine $line, Component $header, Port $branchPort): array
    {
        return $this->resolveVerdict(
            $line,
            $this->dnOfSize($header->nominal_size),
            $this->dnOfSize($branchPort->nominal_size),
        );
    }

    private function dnOfSize(?string $nps): ?int
    {
        if ($nps === null) {
            return null;
        }

        try {
            return $this->reference->size($nps)->dn;
        } catch (UnknownSizeException) {
            return null;
        }
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
        $joint = $joint->fresh(['portA.component', 'portB.component']);
        $line = PipingLine::query()->findOrFail($joint->piping_line_id);
        $project = Project::query()->findOrFail($line->project_id);
        $physicalLineIdA = $joint->portA?->component?->piping_line_id;
        $physicalLineIdB = $joint->portB?->component?->piping_line_id;

        if ($physicalLineIdA !== null && $physicalLineIdB !== null && $physicalLineIdA !== $physicalLineIdB) {
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.cross_line_not_supported'),
            ]);
        }

        $physicalLineId = $physicalLineIdA ?? $physicalLineIdB;

        if ($physicalLineId === null) {
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.port_missing'),
            ]);
        }

        $physicalLine = PipingLine::query()->findOrFail($physicalLineId);
        $branchLine = $joint->isOnWall() ? $physicalLine : null;

        if ($physicalLine->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.port_missing'),
            ]);
        }

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($line);
        $this->assertLineIsModellable($physicalLine);

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

        DB::transaction(function () use ($joint, $line, $physicalLine, $branchLine): void {
            $this->lockLines($line, $physicalLine);
            $freshJoint = Joint::query()->whereKey($joint->id)->lockForUpdate()->firstOrFail();

            if ($freshJoint->isOnWall()) {
                $dependents = Joint::query()
                    ->with(['portA.component.ports', 'portB.component.ports'])
                    ->where('branch_root_joint_id', $freshJoint->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($dependents as $dependent) {
                    if ($dependent->isFitted()) {
                        throw ValidationException::withMessages([
                            'branch_root_joint_id' => 'A fitted branch joint cannot be moved to another line register; use the revision workflow first.',
                        ]);
                    }

                    // Removing the root ends the header association. Keep the
                    // physical branch welds, but move any header-owned numbers
                    // and PMS verdicts back to the branch line; old header
                    // numbers remain gaps.
                    $reassignLine = $dependent->piping_line_id !== $branchLine->id;
                    $ownerVerdict = [];

                    if ($reassignLine) {
                        $portA = $dependent->portA;
                        $portB = $dependent->portB;

                        if ($portA === null || $portB === null) {
                            throw ValidationException::withMessages([
                                'branch_root_joint_id' => 'A dependent joint with an incomplete physical connection cannot be reassigned; use the revision workflow.',
                            ]);
                        }

                        $ownerVerdict = $this->branchVerdictFor($branchLine, $portA, $portB);
                    }

                    $dependent->update([
                        'piping_line_id' => $branchLine->id,
                        'joint_seq' => $reassignLine && $dependent->joint_seq !== null
                            ? $this->nextSeq($branchLine)
                            : $dependent->joint_seq,
                        'branch_root_joint_id' => null,
                        ...$ownerVerdict,
                    ]);
                }
            }

            // A branch joint has no second port; array_filter drops the null.
            $portIds = array_values(array_filter([$freshJoint->port_a_id, $freshJoint->port_b_id]));

            $freshJoint->delete();

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

        $query = Joint::query()
            ->whereIn('port_a_id', $portIds)
            ->orWhereIn('port_b_id', $portIds);

        // A component can also be the HEADER that other lines branch onto.
        // Missing these would reproduce exactly the failure the docblock above
        // describes: the branch would be left recorded as welded to something
        // that no longer exists, invisible to the open-ends worklist.
        $query->orWhere('branch_header_component_id', $component->id);

        return $query->get();
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

        if ($b === null) {
            if ($joint->joint_type === Joint::TYPE_OLET_HEADER_WELD) {
                // The one stored port is the OLET RUN (header) side, while the
                // frozen joint size is deliberately the OLET BRANCH diameter.
                // Using portA would charge a 6x2 OLET as 6 dia-inch instead of
                // 2, and would disagree with the P2P wall-weld convention.
                return $joint->nominal_size_decimal;
            }

            // A fitting-less joint on a header wall has one port by
            // construction. That port is the branch pipe end and therefore
            // already carries the branch size.
            return $a?->nominal_size_decimal;
        }

        if ($a === null) {
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
     * @return array{
     *     welds: int, fitted: int, dia_inch: float, dia_inch_fitted: float,
     *     branch_welds: int, branch_dia_inch: float,
     *     branch_ungoverned: int, branch_padded: int,
     *     branch_by_kind: array<string, array{welds: int, dia_inch: float}>
     * }
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

        // Branch connections, carried separately rather than folded into the
        // totals. The owner's requirement (2026-11-10): a branch costs more to
        // execute and weld than an ordinary weld, so the register has to be
        // able to tell them apart; ADR-008 §9.2 says branch welds must be
        // counted and reported separately and must not be silently absorbed.
        $branchWelds = 0;
        $branchDia = 0.0;
        $byKind = [];
        $ungoverned = 0;
        $padded = 0;

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

            if ($joint->branch_kind === null) {
                continue;
            }

            $branchWelds++;
            $branchDia += $value;

            // The report that gets chased: a branch weld the class never
            // spoke to. Either the line was never given an issued class, or
            // the branch table has no cell for this size pair.
            if ($joint->branch_rule_state !== null
                && $joint->branch_rule_state !== JointBranchRuleState::RULE) {
                $ungoverned++;
            }

            if ($joint->branch_pad_thickness_mm !== null) {
                $padded++;
            }

            $byKind[$joint->branch_kind] ??= ['welds' => 0, 'dia_inch' => 0.0];
            $byKind[$joint->branch_kind]['welds']++;
            $byKind[$joint->branch_kind]['dia_inch'] += $value;
        }

        foreach ($byKind as $kind => $totals) {
            $byKind[$kind]['dia_inch'] = round($totals['dia_inch'], 4);
        }

        return [
            // Totals are unchanged in meaning and still INCLUDE branch welds:
            // a branch weld is a weld, and a dia-inch is a dia-inch. What was
            // wrong before was that nothing could see them separately, not
            // that they were counted. The complement is welds - branch_welds.
            'welds' => $welds,
            'fitted' => $fitted,
            'dia_inch' => round($dia, 4),
            'dia_inch_fitted' => round($diaFitted, 4),

            'branch_welds' => $branchWelds,
            'branch_dia_inch' => round($branchDia, 4),
            'branch_ungoverned' => $ungoverned,
            'branch_padded' => $padded,

            // Only kinds that actually occur are emitted. Absence means no
            // joint of that kind is recorded; it does not certify that the
            // source design was searched or imported completely.
            'branch_by_kind' => $byKind,
        ];
    }

    // ------------------------------------------------------------ helpers --

    /** Resolve an explicit owner selection; legacy callers keep branch ownership. */
    private function lineOwnerFrom(array $attributes): JointLineOwner
    {
        $value = strtoupper(trim((string) ($attributes['line_owner'] ?? JointLineOwner::BRANCH->value)));
        $owner = JointLineOwner::tryFrom($value);

        if ($owner === null) {
            throw ValidationException::withMessages([
                'line_owner' => 'The joint line owner must be BRANCH or HEADER.',
            ]);
        }

        return $owner;
    }

    private function branchRootIdFrom(array $attributes): ?string
    {
        $value = trim((string) ($attributes['branch_root_joint_id'] ?? ''));

        if ($value === '') {
            return null;
        }

        if (! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The branch root must be a valid joint identifier.',
            ]);
        }

        return $value;
    }

    /** A directly selected project-line owner for a TEE/CROSS branch-port weld. */
    private function headerLineIdFrom(array $attributes): ?string
    {
        $value = $attributes['header_line_id'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'header_line_id' => 'The selected header line must be a valid line identifier.',
            ]);
        }

        return $value;
    }

    /**
     * Resolve a directly selected header line without using a wall-root link.
     * Cross-project or missing line IDs are deliberately indistinguishable to
     * the caller and never disclose another project's line.
     */
    private function headerLineById(string $lineId, PipingLine $branchLine): PipingLine
    {
        $line = PipingLine::query()
            ->where('project_id', $branchLine->project_id)
            ->whereKey($lineId)
            ->first();

        if ($line === null) {
            throw ValidationException::withMessages([
                'header_line_id' => 'The selected header line must belong to the same project.',
            ]);
        }

        return $line;
    }

    /**
     * Find a P2P/OLET wall root that proves which header may own a joint from
     * this physical branch line.
     */
    private function branchRootForLine(?string $rootId, PipingLine $branchLine): ?Joint
    {
        if ($rootId === null) {
            return null;
        }

        $root = Joint::query()
            ->with(['portA.component', 'branchHeaderComponent'])
            ->whereKey($rootId)
            ->first();
        $branchComponent = $root?->portA?->component;
        $header = $root?->branchHeaderComponent;

        if ($root === null || $root->port_b_id !== null
            || ! in_array($root->joint_type, [
                Joint::TYPE_BRANCH_STUB_IN,
                Joint::TYPE_BRANCH_STUB_ON,
                Joint::TYPE_OLET_HEADER_WELD,
            ], true)
            || $branchComponent === null || $branchComponent->piping_line_id !== $branchLine->id
            || $root->project_id !== $branchLine->project_id
            || $header === null || $header->template_code !== 'PIPE'
            || $header->project_id !== $branchLine->project_id) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The selected root must be a P2P/OLET wall joint for this branch line.',
            ]);
        }

        $headerLine = PipingLine::query()->find($header->piping_line_id);

        if ($headerLine === null || $headerLine->project_id !== $branchLine->project_id) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The selected branch root does not reference a header line in this project.',
            ]);
        }

        return $root;
    }

    private function headerLineForRoot(Joint $root): PipingLine
    {
        $header = $root->branchHeaderComponent;

        if ($header === null) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The branch root has no header component.',
            ]);
        }

        return PipingLine::query()->findOrFail($header->piping_line_id);
    }

    /** Lock all sequence-owner/physical lines once, in a stable order. */
    private function lockLines(PipingLine ...$lines): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (PipingLine $line): string => $line->id,
            $lines,
        )));
        sort($ids, SORT_STRING);

        foreach ($ids as $id) {
            PipingLine::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * Whether this two-port joint is a branch connection, and of what kind.
     *
     * TEE and CROSS branch welds are decided by port ROLE, not by size or end
     * type. OLET is deliberately excluded: its class-level branch connection
     * is the fabricated RUN-to-header-wall weld, classified explicitly by
     * `joinOletToWall()`. The OLET BRANCH-to-pipe weld is an ordinary weld, not
     * a second branch connection.
     *
     * Fitting-less P2P wall welds are also explicit and are classified on the
     * separate `joinToWall()` path (ADR-006 §14). See JointBranchKind.
     *
     * @return string|null null for a weld in the run, which is the ordinary case
     */
    public function branchKindFor(Port $a, Port $b): ?string
    {
        $port = $this->branchPortOf($a, $b);

        if ($port === null || $port->component === null) {
            return null;
        }

        $kind = JointBranchKind::fromTemplate((string) $port->component->template_code);

        if ($kind === null) {
            throw UnknownBranchTemplate::forTemplate((string) $port->component->template_code);
        }

        return $kind;
    }

    /**
     * The TEE/CROSS port that makes this two-port joint a branch connection.
     *
     * Deterministic order: a joint with a branch port on BOTH sides is not a
     * shape the templates produce, but if one ever appears the answer must not
     * depend on which way round the caller passed the ports. An OLET BRANCH
     * port is intentionally skipped; its branch classification belongs to the
     * OLET RUN-to-header wall weld, not this outlet butt weld.
     */
    private function branchPortOf(Port $a, Port $b): ?Port
    {
        foreach ([$a, $b] as $port) {
            if ($port->role === 'BRANCH' && $port->component?->template_code !== 'OLET') {
                return $port;
            }
        }

        return null;
    }

    /**
     * What the line's issued PMS class says this branch should be.
     *
     * Read from the ISSUED SNAPSHOT, never from the live piping-class rows.
     * ComponentService settled that convention for pipe schedules - "inherits
     * the line's issued PMS specification, not the mutable/current piping-class
     * rules" - and a weld is a stronger case for it still: a joint is a
     * physical fact, and a class revision issued next month must not
     * retroactively change what this weld was built to.
     *
     * Asked only for branch welds. A weld in the run has no business answering
     * for a branch table, and asking would put a verdict on every joint in the
     * line.
     *
     * Records rather than refuses. Plant 3D refuses to connect when the branch
     * table has no cell for the pair, which is the right behaviour once there
     * is a screen for the branch table - there is not one today, so a refusal
     * would be a wall the user cannot climb. Every ungoverned branch weld is
     * instead marked, and `branch_ungoverned` in lineMetrics() counts them.
     *
     * @return array{branch_class_type: string|null, branch_class_p2p_kind: string|null, branch_pad_thickness_mm: float|null, branch_rule_state: string|null}
     */
    public function branchVerdictFor(PipingLine $line, Port $a, Port $b): array
    {
        $unasked = [
            'branch_class_type' => null,
            'branch_class_p2p_kind' => null,
            'branch_pad_thickness_mm' => null,
            'branch_rule_state' => null,
        ];

        $branchPort = $this->branchPortOf($a, $b);

        if ($branchPort === null) {
            return $unasked;
        }

        $fitting = $branchPort->component;

        // The branch table is keyed on hEADER x BRANCH. The header is the
        // fitting's run size - which is NOT any port of this joint. A tee's
        // branch weld carries the branch size on both of its ports; the run
        // size only exists on the tee's sibling RUN port.
        $headerDn = $this->dnOfPort($fitting?->ports->firstWhere('role', 'RUN'));
        $branchDn = $this->dnOfPort($branchPort);

        if ($headerDn === null || $branchDn === null) {
            // Could not even ask. Treated as NO_RULE rather than inventing a
            // fourth state: the action is the same, open the branch table.
            return [...$unasked, 'branch_rule_state' => JointBranchRuleState::NO_RULE];
        }

        return $this->resolveVerdict($line, $headerDn, $branchDn, $unasked);
    }

    /**
     * Ask the line's issued class what this header x branch pair should be.
     *
     * Shared by fitting branches and wall branches, so that both are held to
     * the same answer whether or not the branch has a fitting in it.
     *
     * @param  array<string, mixed>|null  $unasked  what to return when there is nothing to ask
     * @return array{branch_class_type: string|null, branch_class_p2p_kind: string|null, branch_pad_thickness_mm: float|null, branch_rule_state: string|null}
     */
    private function resolveVerdict(
        PipingLine $line,
        ?int $headerDn,
        ?int $branchDn,
        ?array $unasked = null,
    ): array {
        $unasked ??= [
            'branch_class_type' => null,
            'branch_class_p2p_kind' => null,
            'branch_pad_thickness_mm' => null,
            'branch_rule_state' => null,
        ];

        if ($headerDn === null || $branchDn === null) {
            // Could not even ask. Treated as NO_RULE rather than inventing a
            // fourth state: the action is the same, open the branch table.
            return [...$unasked, 'branch_rule_state' => JointBranchRuleState::NO_RULE];
        }

        try {
            $specification = $this->issuedPms->forLine($line);
        } catch (ValidationException) {
            return [...$unasked, 'branch_rule_state' => JointBranchRuleState::NO_SNAPSHOT];
        }

        try {
            $rule = $this->issuedPms->branchRuleFromSpecification($specification, $headerDn, $branchDn);
        } catch (ValidationException) {
            return [...$unasked, 'branch_rule_state' => JointBranchRuleState::NO_RULE];
        }

        // Only a PAD rule carries a thickness - the class table enforces the
        // pairing with a CHECK, but the snapshot is a JSON copy and a copy can
        // drift. Re-stating the invariant here keeps a stray number off a weld
        // that was never padded.
        $padded = ($rule['reinforcement'] ?? null) === 'PAD';

        return [
            'branch_class_type' => $rule['component_type'] ?? null,
            'branch_class_p2p_kind' => $rule['p2p_kind'] ?? null,
            'branch_pad_thickness_mm' => $padded && isset($rule['pad_thickness'])
                ? (float) $rule['pad_thickness']
                : null,
            'branch_rule_state' => JointBranchRuleState::RULE,
        ];
    }

    /** DN of a port's nominal size, or null when the port or size is unknown. */
    private function dnOfPort(?Port $port): ?int
    {
        if ($port === null || $port->nominal_size === null) {
            return null;
        }

        try {
            return $this->reference->size((string) $port->nominal_size)->dn;
        } catch (UnknownSizeException) {
            return null;
        }
    }

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

    private function lineFor(Port $a, ?Port $b): PipingLine
    {
        $componentA = Component::query()->find($a->component_id);
        // A joint on a header wall has no second port: the other side
        // is a point on the header, which is a different line entirely.
        $componentB = $b === null ? null : Component::query()->find($b->component_id);

        // A NOZZLE port belongs to equipment, not to a line, so it claims no
        // line of its own: the joint takes the line from whichever side does
        // have one. This is the case ADR-010 §3.1 exists for — the joint
        // table needed no change to hold a pipe-to-nozzle weld, only this
        // lookup did.
        if ($componentA === null && $componentB === null) {
            // Two nozzles and no pipe: there is no line to number the joint
            // against, and piping_line_id is NOT NULL. Refused rather than
            // made up.
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.both_ends_are_nozzles'),
            ]);
        }

        $lineA = $componentA?->piping_line_id;
        $lineB = $componentB?->piping_line_id;

        if ($lineA !== null && $lineB !== null && $lineA !== $lineB) {
            // Cross-line joints are real (a tie-in), but they need a decision
            // about which line owns the number before they can be allowed.
            throw ValidationException::withMessages([
                'port_id' => __('epcc.joint.cross_line_not_supported'),
            ]);
        }

        return PipingLine::query()->findOrFail($lineA ?? $lineB);
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
