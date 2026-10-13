<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A numbered note on a piping class header (ADR-008 §11.6).
 *
 * A PMS header ends in a numbered list, and body rows cite those numbers.
 * Holding the notes as prose in one column would make "note (4)" unresolvable,
 * so each is a row and the number is unique inside the class.
 */
final class PipingClassNote extends Model
{
    use HasUuid;

    protected $table = 'piping_class_notes';

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_id',
        'number', 'text_en', 'text_fa',
    ];

    protected $casts = ['number' => 'integer'];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class, 'piping_class_id');
    }

    public function text(string $lang): string
    {
        return (string) ($lang === 'fa'
            ? ($this->text_fa ?? $this->text_en ?? '')
            : ($this->text_en ?? $this->text_fa ?? ''));
    }
}
