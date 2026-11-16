<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable project-local ENU frame definition (ADR-061). */
final class ProjectModelFrameRevision extends Model
{
    use HasUuid;

    public const FRAME_CODE = 'PROJECT_MODEL_ENU';

    public const AXIS_CONVENTION = 'RIGHT_HANDED_ENU_V1';

    public const COORDINATE_UNIT = 'MM';

    protected $table = 'project_model_frame_revisions';

    protected $fillable = [
        'project_id', 'company_id', 'revision', 'frame_code',
        'axis_convention', 'coordinate_unit', 'origin_description',
        'created_by', 'created_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poseRevisions(): HasMany
    {
        return $this->hasMany(ComponentPoseRevision::class, 'model_frame_revision_id');
    }

    /** Stable opaque identifier for this exact frame revision. */
    public function frameIdentifier(): string
    {
        return 'project:'.$this->project_id.':model:'.$this->id.':r'.$this->revision;
    }
}
