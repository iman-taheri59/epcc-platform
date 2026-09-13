<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\CdeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One issued revision of a document (SRS FR-ENG-03, FR-ENG-04).
 *
 * Rows here are the audit trail, so they are append-mostly: a new issue creates
 * a new row rather than editing the last one. Only the CDE state of the current
 * revision moves.
 */
final class DocumentRevision extends Model
{
    use HasUuid;

    protected $fillable = [
        'document_id', 'project_id', 'company_id', 'revision', 'ordinal',
        'cde_state', 'suitability', 'issued_by', 'issued_at', 'remarks',
    ];

    protected $casts = ['issued_at' => 'datetime'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Review comments raised against this revision (FR-ENG-06). */
    public function comments(): HasMany
    {
        return $this->hasMany(ReviewComment::class, 'revision_id')->orderBy('seq');
    }

    /** Files issued WITH this revision (FR-DOC-01). */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class, 'revision_id')->orderBy('original_name');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function stateLabel(string $lang): string
    {
        return CdeState::label($this->cde_state, $lang);
    }

    /** @return list<string> */
    public function nextStates(): array
    {
        return CdeState::nextFrom($this->cde_state);
    }
}
