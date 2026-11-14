<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical reinforcement pad at a direct P2P stub-in/stub-on wall root.
 *
 * The part lives on the physical branch line; its two independently numbered
 * fillet joints are registered on the header line. Thickness and material
 * provenance are frozen from the header line's issued PMS snapshot (PAD
 * material defaults to the header PIPE_MATERIAL unless the branch rule declares
 * `value.pad_material`); the ideal contact curve, separate sampled outer
 * perimeter, bounding spans, inputs and assumptions are frozen at installation.
 */
final class BranchPad extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'component_id', 'branch_root_joint_id',
        'header_pipe_component_id', 'branch_pipe_component_id', 'branch_pipe_port_id',
        'header_line_id', 'pms_class_snapshot_id',
        'header_joint_id', 'branch_joint_id',
        'branch_rule_component_type', 'branch_rule_p2p_kind', 'pad_thickness_mm',
        'pad_major_axis_mm', 'pad_minor_axis_mm',
        'pad_major_axis_direction', 'pad_minor_axis_direction',
        'pad_geometry_d2_mm', 'pad_geometry_beta_deg', 'pad_geometry_method',
        'pad_geometry_snapshot', 'created_by',
    ];

    protected $casts = [
        'pad_thickness_mm' => 'float',
        'pad_major_axis_mm' => 'float',
        'pad_minor_axis_mm' => 'float',
        'pad_geometry_d2_mm' => 'float',
        'pad_geometry_beta_deg' => 'float',
        'pad_geometry_snapshot' => 'array',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function branchRootJoint(): BelongsTo
    {
        return $this->belongsTo(Joint::class, 'branch_root_joint_id');
    }

    public function headerPipe(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'header_pipe_component_id');
    }

    public function branchPipe(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'branch_pipe_component_id');
    }

    public function branchPipePort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'branch_pipe_port_id');
    }

    public function headerLine(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'header_line_id');
    }

    public function pmsClassSnapshot(): BelongsTo
    {
        return $this->belongsTo(PmsClassRevisionSnapshot::class, 'pms_class_snapshot_id');
    }

    public function headerJoint(): BelongsTo
    {
        return $this->belongsTo(Joint::class, 'header_joint_id');
    }

    public function branchJoint(): BelongsTo
    {
        return $this->belongsTo(Joint::class, 'branch_joint_id');
    }
}
