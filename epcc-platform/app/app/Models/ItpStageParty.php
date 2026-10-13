<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Itp\InterventionCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the ITP party grid: what THIS kind of company does at THIS stage.
 *
 * Keyed by tenant type rather than company id on purpose - an ITP is written
 * before the TPI firm is appointed and outlives that firm being replaced.
 */
final class ItpStageParty extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'itp_stage_id', 'tenant_type', 'intervention',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ItpStage::class, 'itp_stage_id');
    }

    public function blocks(): bool
    {
        return InterventionCode::blocks($this->intervention);
    }

    public function requiresNotice(): bool
    {
        return InterventionCode::requiresNotice($this->intervention);
    }

    /** @return array{fa:string,en:string} */
    public function label(): array
    {
        return InterventionCode::label($this->intervention);
    }
}
