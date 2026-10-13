<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PMS-owned source requirement, not a material inspection or a test result. */
final class PipingClassNdtRequirement extends Model
{
    use HasUuid;

    public const FIELDS = ['applicability' => 2000, 'method' => 120, 'extent' => 1000,
        'sampling_basis' => 2000, 'acceptance' => 4000, 'reference' => 2000,
        'conditions' => 4000, 'source_ref' => 1000];

    public const REQUIRED = ['applicability', 'method', 'source_ref'];

    protected $fillable = ['applicability', 'method', 'extent', 'sampling_basis', 'acceptance', 'reference', 'conditions', 'source_ref'];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class, 'piping_class_id');
    }

    public function missingDetails(): array
    {
        return array_values(array_filter(['extent', 'sampling_basis', 'acceptance', 'reference'],
            fn (string $field): bool => $this->{$field} === null || preg_match('/^[\s\p{Z}]*$/u', $this->{$field}) === 1));
    }
}
