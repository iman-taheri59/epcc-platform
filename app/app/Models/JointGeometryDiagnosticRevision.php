<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable, versioned snapshot of one joint-geometry diagnostic. */
final class JointGeometryDiagnosticRevision extends Model
{
    use HasUuid;

    protected $table = 'joint_geometry_diagnostic_revisions';

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id', 'joint_id', 'revision',
        'method_code', 'source_fingerprint', 'diagnostic_status', 'diagnostic_reason',
        'source_snapshot', 'diagnostic_snapshot', 'created_by', 'created_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'source_snapshot' => 'array',
        'diagnostic_snapshot' => 'array',
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

    /** May be null after the live joint is removed; the snapshot remains evidence. */
    public function joint(): BelongsTo
    {
        return $this->belongsTo(Joint::class, 'joint_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
