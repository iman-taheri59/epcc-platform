<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Project-sourced, unreviewed declaration. Never an ASME reference or model update. */
final class ProjectFittingDefinition extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'project_id', 'company_id', 'created_by', 'provenance', 'review_state', 'revises_id', 'root_id', 'correction_reason'];

    public function setPropertiesAttribute($value): void
    {
        $this->attributes['properties'] = json_encode((object)$value, JSON_THROW_ON_ERROR);
    }
    protected $casts = [
        'properties' => 'array', 'nps' => 'decimal:4', 'od_mm' => 'decimal:4', 'dimension_mm' => 'decimal:4', 'dn' => 'integer',
    ];
}
