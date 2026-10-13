<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Itp\StageResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ITP stage applied to one real thing, with its verdict. */
final class ItpRecord extends Model
{
    use HasUuid;

    public const SUBJECT_MATERIAL_LOT = 'MATERIAL_LOT';

    protected $fillable = [
        'project_id', 'company_id', 'itp_stage_id', 'subject_kind',
        'material_lot_id', 'result', 'findings_en', 'findings_fa',
        'signed_by', 'signed_company_id', 'signed_tenant_type', 'signed_at',
        'notified_at', 'notified_by', 'notice_reference', 'evidence_document_id',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ItpStage::class, 'itp_stage_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class, 'material_lot_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }

    public function satisfied(): bool
    {
        return StageResult::satisfied($this->result);
    }

    public function isOpen(): bool
    {
        return StageResult::isOpen($this->result);
    }

    public function findings(string $lang = 'fa'): ?string
    {
        return $lang === 'fa' ? $this->findings_fa : $this->findings_en;
    }

    /** @return array{fa:string,en:string} */
    public function label(): array
    {
        return StageResult::label($this->result);
    }
}
