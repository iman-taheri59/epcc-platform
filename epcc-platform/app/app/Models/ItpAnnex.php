<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document bound in with an ITP: usually a blank inspection report form.
 *
 * This is the annex list on an ITP cover sheet — "Annex A: Incoming material
 * inspection report", "Annex B: PMI record". The forms travel WITH the plan
 * when it is issued, which is why they hang off the ITP rather than off the
 * individual rows.
 *
 * `itp_stage_documents` with usage=FORM still says which form a given ROW
 * uses. The two answer different questions: this one is "what is bound into
 * this document", that one is "which form does the inspector fill in at clause
 * 1.4". A forty-row ITP has two or three annexes, not forty.
 */
final class ItpAnnex extends Model
{
    use HasUuid;

    /** A blank inspection report form. */
    public const FORM = 'FORM';

    /** A method statement or NDE procedure issued with the plan. */
    public const PROCEDURE = 'PROCEDURE';

    /** Anything else bound in for the inspector's convenience. */
    public const REFERENCE = 'REFERENCE';

    /** @var list<string> */
    public const KINDS = [self::FORM, self::PROCEDURE, self::REFERENCE];

    protected $fillable = [
        'project_id', 'company_id', 'itp_id', 'document_id',
        'label', 'sequence', 'kind',
    ];

    protected $casts = ['sequence' => 'integer'];

    public function itp(): BelongsTo
    {
        return $this->belongsTo(Itp::class, 'itp_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public static function kindIsValid(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /** "Annex A" / «پیوست A», or just the document number when unlabelled. */
    public function caption(string $lang = 'fa'): string
    {
        if ($this->label === null || $this->label === '') {
            return (string) $this->document?->number;
        }

        return ($lang === 'fa' ? 'پیوست ' : 'Annex ').$this->label;
    }
}
