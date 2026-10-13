<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A speciality item in the project's SP register (ADR-008 §11.9).
 *
 * SP-014 is a SPECIFICATION — "what it is" — and is unique across the whole
 * project, referenced by however many piping classes use it. It is not an
 * instance: a tagged special also has a tag saying "which one it is", and where
 * that lives is still an open decision (§11.9), so nothing here holds one.
 *
 * The number is transcribed from the client's document and validated, never
 * allocated.
 */
final class SpecialtyItem extends Model
{
    use HasUuid;

    protected $table = 'specialty_items';

    protected $fillable = [
        'project_id', 'company_id', 'sp_number',
        'description_en', 'description_fa', 'kind_as_written',
        'is_tagged', 'datasheet_document_id',
    ];

    protected $casts = ['is_tagged' => 'boolean'];

    public function datasheet(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'datasheet_document_id');
    }

    /** Every class body row that calls for this special. */
    public function items(): HasMany
    {
        return $this->hasMany(PipingClassItem::class, 'specialty_item_id');
    }

    public function description(string $lang): string
    {
        return (string) ($lang === 'fa'
            ? ($this->description_fa ?? $this->description_en ?? $this->kind_as_written ?? $this->sp_number)
            : ($this->description_en ?? $this->description_fa ?? $this->kind_as_written ?? $this->sp_number));
    }

    /**
     * An SP number is only usable by MTO once somebody can tell what it is.
     * A bare number in the register is a placeholder, not an item.
     */
    public function isDescribed(): bool
    {
        return trim((string) ($this->description_en ?? '')) !== ''
            || trim((string) ($this->description_fa ?? '')) !== ''
            || trim((string) ($this->kind_as_written ?? '')) !== '';
    }
}
