<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Component;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\JointBranchKind;
use App\Support\Piping\JointBranchRuleState;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two ports, joined (ADR-006 section 5.3).
 *
 * The pair is ORDERED - port_a_id < port_b_id, enforced by a CHECK - so that
 * (A,B) and (B,A) cannot both exist and be counted twice. Nothing in the
 * application should construct a Joint directly; JointService::join() sorts the
 * pair before it writes.
 */
final class Joint extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id',
        'port_a_id', 'port_b_id',
        'joint_seq', 'numbering_policy',
        'joint_type', 'branch_kind', 'state',
        'branch_class_type', 'branch_class_p2p_kind',
        'branch_header_component_id', 'branch_station_mm', 'branch_offset_mm',
        'branch_pad_thickness_mm', 'branch_rule_state',
        'fitted_by_code', 'fitted_at',
        'nominal_size', 'nominal_size_decimal', 'schedule', 'thickness_mm',
        'shop_field', 'spool_id', 'isometric_sheet_no',
        'compatibility_state', 'compatibility_notes',
        'source', 'external_ref', 'created_by',
    ];

    protected $casts = [
        'joint_seq' => 'integer',
        'nominal_size_decimal' => 'float',
        'thickness_mm' => 'float',
        'branch_pad_thickness_mm' => 'float',
        'branch_station_mm' => 'float',
        'branch_offset_mm' => 'float',
        'compatibility_notes' => 'array',
        'fitted_at' => 'datetime',
    ];

    // ADR-008 §9.2 / P-21: branch welds are a formal joint type, not a butt
    // weld with an asterisk. They are counted and reported separately, and
    // dia-inch must not silently absorb them.
    public const TYPE_BRANCH_STUB_IN = 'BRANCH_STUB_IN';

    public const TYPE_BRANCH_STUB_ON = 'BRANCH_STUB_ON';

    public const STATE_MODELLED = 'MODELLED';

    public const STATE_FITTED = 'FITTED';

    public const NUMBERING_REQUIRED = 'REQUIRED';

    public const NUMBERING_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public function portA(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_a_id');
    }

    public function portB(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_b_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function scopeOnLine(Builder $query, string $lineId): Builder
    {
        return $query->where('piping_line_id', $lineId);
    }

    /**
     * Joints that should have a number and do not - the chase-list.
     *
     * Distinct from "unnumbered": a flanged joint is unnumbered forever and
     * correctly so. Only REQUIRED rows belong on anyone's worklist.
     */
    public function scopeAwaitingNumber(Builder $query): Builder
    {
        return $query->where('numbering_policy', self::NUMBERING_REQUIRED)->whereNull('joint_seq');
    }

    /**
     * The number as people write it: zero-padded to three.
     *
     * Padding lives here and not in the column, so that sorting and "next
     * number" stay integer arithmetic (ADR-006 section 11.1).
     */
    public function displayNumber(): ?string
    {
        return $this->joint_seq === null ? null : str_pad((string) $this->joint_seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Does this joint contribute to welded progress?
     *
     * Only welds do. A flanged joint is real work, but it is bolt-up work and
     * belongs in a different statement - counting it as dia-inch would inflate
     * the welding figure the project is paid against.
     */
    public function isWeld(): bool
    {
        return in_array($this->joint_type, [
            'BUTT_WELD', 'SOCKET_WELD', 'FABRICATED',

            // A branch weld is a weld. Leaving these out would drop the most
            // expensive welds on the line out of every dia-inch roll-up, which
            // is the opposite of what ADR-008 §9.2 asked for.
            self::TYPE_BRANCH_STUB_IN, self::TYPE_BRANCH_STUB_ON,
        ], true);
    }

    public function isFitted(): bool
    {
        return $this->state === self::STATE_FITTED;
    }

    /**
     * Whether this branch weld contradicts the class it was built to.
     *
     * ADR-008 section 9.2: "a joint contradicting its class is a deviation".
     * The comparison is possible only because the register keeps its own
     * OBSERVATION (`branch_kind`) alongside the class's DECLARATION
     * (`branch_class_type`); overwriting one with the other would destroy the
     * only evidence that they differ.
     *
     * Returns null for anything that is not a branch weld - a weld in the run
     * has no verdict to contradict.
     *
     * `UNMATCHED` is the honest answer where the vocabularies cannot be made
     * to meet: a CROSS branch is a branch connection, but BranchConnectionType
     * has no CROSS (TEE/WELDOLET/SOCKOLET/P2P only), so no declared value can
     * ever agree with it. Calling that a deviation would be wrong - the gap is
     * in the PMS vocabulary, not in the weld.
     */
    public function branchDeviation(): ?string
    {
        if ($this->branch_kind === null) {
            return null;
        }

        if ($this->branch_rule_state !== JointBranchRuleState::RULE) {
            // Not a contradiction: nobody ever said what this should be. The
            // action is to classify the line or add the missing cell, not to
            // chase the weld.
            return 'UNGOVERNED';
        }

        return match ([$this->branch_class_type, $this->branch_kind]) {
            // The class says no fitting, and a fitting was used anyway. This is
            // the expensive one: a purchased fitting appears in the MTO that
            // the class never called for.
            [BranchConnectionType::P2P, JointBranchKind::TEE],
            [BranchConnectionType::P2P, JointBranchKind::CROSS],
            [BranchConnectionType::P2P, JointBranchKind::OLET] => 'FITTING_WHERE_CLASS_SAYS_NONE',

            // The class asked for no fitting, and none was used — but WHICH
            // stub matters. A stub-in seats inside the header bore and a
            // stub-on saddles the outside; they are different welds and take
            // different preparation.
            [BranchConnectionType::P2P, JointBranchKind::STUB_IN],
            [BranchConnectionType::P2P, JointBranchKind::STUB_ON] =>
                $this->branch_class_p2p_kind === $this->branch_kind ? 'AGREES' : 'MISMATCH',

            [BranchConnectionType::TEE, JointBranchKind::TEE] => 'AGREES',

            // An olet is the fitting that realises a WELDOLET or SOCKOLET cell.
            [BranchConnectionType::WELDOLET, JointBranchKind::OLET],
            [BranchConnectionType::SOCKOLET, JointBranchKind::OLET] => 'AGREES',

            // A cross cannot be expressed in the class vocabulary at all.
            [BranchConnectionType::TEE, JointBranchKind::CROSS],
            [BranchConnectionType::WELDOLET, JointBranchKind::CROSS],
            [BranchConnectionType::SOCKOLET, JointBranchKind::CROSS] => 'UNMATCHED',

            default => 'MISMATCH',
        };
    }

    /**
     * The header this branch is welded onto, when the joint is on a wall.
     *
     * Null for every ordinary joint. A pipe has two ends and no port on its
     * side, so the header contributes no port — the joint points at the
     * component and at a location on it instead (ADR-006 §13, P-22).
     */
    public function branchHeaderComponent(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'branch_header_component_id');
    }

    /** True when this joint lands on a header wall rather than another port. */
    public function isOnWall(): bool
    {
        return $this->branch_header_component_id !== null;
    }

    /** The other end of the joint, given one of its ports. */
    public function counterpartOf(string $portId): ?string
    {
        return match ($portId) {
            $this->port_a_id => $this->port_b_id,
            $this->port_b_id => $this->port_a_id,
            default => null,
        };
    }
}
