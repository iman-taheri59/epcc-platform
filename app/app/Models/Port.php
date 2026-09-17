<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A connection point (ADR-006 §5.3, ADR-010 §3).
 *
 * ONE table for both component ports and equipment-nozzle ports, discriminated
 * by `owner_kind`. A joint joins two ports and does not care which kind they
 * are; sibling tables would force every joint query into a two-branch COALESCE
 * and give the compatibility engine parallel code paths that drift apart.
 *
 * The nozzle side lands in phase 3b. The column and its CHECK exist now so
 * that arrival is an INSERT rather than a migration.
 */
final class Port extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id',
        'owner_kind', 'component_id', 'nozzle_id',
        'seq', 'role',
        'nominal_size', 'nominal_size_decimal',
        'end_type', 'schedule', 'is_connected',
    ];

    protected $casts = [
        'nominal_size_decimal' => 'float',
        'is_connected' => 'boolean',
        'seq' => 'integer',
    ];

    public const OWNER_COMPONENT = 'COMPONENT';

    public const OWNER_NOZZLE = 'NOZZLE';

    /** @return list<string> */
    public static function ownerKinds(): array
    {
        return [self::OWNER_COMPONENT, self::OWNER_NOZZLE];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_connected', false);
    }

    public function scopeOnLine(Builder $query, string $lineId): Builder
    {
        return $query->whereHas('component', fn (Builder $q) => $q->where('piping_line_id', $lineId));
    }

    /** "P3 BRANCH 2in" - what the attach picker shows. */
    public function label(): string
    {
        $parts = ['P'.$this->seq, $this->role];

        if ($this->nominal_size !== null) {
            $parts[] = $this->nominal_size.'"';
        }

        return implode(' ', $parts);
    }
}
