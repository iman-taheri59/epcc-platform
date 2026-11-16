<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable manual orientation input and resolved geometry snapshot. */
final class ComponentPoseRevision extends Model
{
    use HasUuid;

    protected $table = 'component_pose_revisions';

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id', 'component_id',
        'model_frame_revision_id', 'revision', 'local_frame_id',
        'orientation_method', 'input_azimuth_deg', 'input_inclination_deg',
        'input_roll_deg', 'resolved_angles_deg', 'input_geometry',
        'anchor_easting_mm', 'anchor_northing_mm', 'anchor_elevation_mm',
        'coordinate_unit', 'rotation_local_to_model',
        'geometry_profile_id', 'geometry_profile_revision', 'geometry_profile_hash',
        'resolved_port_geometry', 'geometry_status', 'unresolved_reason',
        'created_by', 'created_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'input_azimuth_deg' => 'float',
        'input_inclination_deg' => 'float',
        'input_roll_deg' => 'float',
        'resolved_angles_deg' => 'array',
        'input_geometry' => 'array',
        'anchor_easting_mm' => 'float',
        'anchor_northing_mm' => 'float',
        'anchor_elevation_mm' => 'float',
        'rotation_local_to_model' => 'array',
        'resolved_port_geometry' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function modelFrameRevision(): BelongsTo
    {
        return $this->belongsTo(ProjectModelFrameRevision::class, 'model_frame_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
