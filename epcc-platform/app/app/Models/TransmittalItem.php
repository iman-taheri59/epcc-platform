<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\CdeState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One revision on a transmittal (FR-ENG-05).
 *
 * The `*_at_issue` columns are a deliberate snapshot: a transmittal must still
 * read correctly after the revision it points at has moved on. They are what
 * gets printed on the sheet and written into the ZIP manifest.
 */
final class TransmittalItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'transmittal_id', 'project_id', 'company_id', 'document_id',
        'revision_id', 'source_item_id', 'number_at_issue', 'revision_at_issue',
        'state_at_issue', 'suitability_at_issue',
    ];

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'revision_id');
    }

    public function stateLabel(string $lang): string
    {
        return CdeState::label($this->state_at_issue, $lang);
    }

    /**
     * The vendor transmittal item this one was rolled up from, if any.
     *
     * This is the traceability chain: vendor letter -> EPC letter -> client.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_item_id');
    }
}
