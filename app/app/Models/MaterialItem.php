<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stable material identity used before MTO rows can be accepted. */
final class MaterialItem extends Model
{
    use HasUuid;

    public const CATEGORY_PIPE = 'PIPE';
    public const CATEGORY_FITTING = 'FITTING';
    public const CATEGORY_FLANGE = 'FLANGE';
    public const CATEGORY_VALVE = 'VALVE';
    public const CATEGORY_GASKET = 'GASKET';
    public const CATEGORY_BOLT_NUT = 'BOLT_NUT';
    public const CATEGORY_SPECIALTY = 'SPECIALTY';
    public const CATEGORY_SUPPORT = 'SUPPORT';

    public const SOURCE_REFERENCE_PACK = 'REFERENCE_PACK';
    public const SOURCE_PMS = 'PMS';
    public const SOURCE_PROJECT_CATALOGUE = 'PROJECT_CATALOGUE';
    public const SOURCE_MANUAL_APPROVED = 'MANUAL_APPROVED';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_WITHDRAWN = 'WITHDRAWN';
    public const STATUS_PROJECT_ONLY = 'PROJECT_ONLY';

    protected $fillable = [
        'project_id', 'reference_item_id', 'discipline', 'category', 'commodity_code',
        'description_en', 'description_fa', 'base_unit', 'size_nps', 'size_dn',
        'schedule', 'rating', 'class_code', 'material_spec', 'grade', 'source',
        'status', 'alias_codes',
    ];

    protected $casts = [
        'alias_codes' => 'array',
    ];

    public static function categories(): array
    {
        return [
            self::CATEGORY_PIPE,
            self::CATEGORY_FITTING,
            self::CATEGORY_FLANGE,
            self::CATEGORY_VALVE,
            self::CATEGORY_GASKET,
            self::CATEGORY_BOLT_NUT,
            self::CATEGORY_SPECIALTY,
            self::CATEGORY_SUPPORT,
        ];
    }

    public static function sources(): array
    {
        return [
            self::SOURCE_REFERENCE_PACK,
            self::SOURCE_PMS,
            self::SOURCE_PROJECT_CATALOGUE,
            self::SOURCE_MANUAL_APPROVED,
        ];
    }

    public static function statuses(): array
    {
        return [self::STATUS_ACTIVE, self::STATUS_WITHDRAWN, self::STATUS_PROJECT_ONLY];
    }

    public static function units(): array
    {
        return ['M', 'EA', 'KG'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function referenceItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_item_id');
    }

    public function projectOverlays(): HasMany
    {
        return $this->hasMany(self::class, 'reference_item_id');
    }

    public function scopeForProjectCatalogue(Builder $query, string $projectId): Builder
    {
        return $query->where(function (Builder $q) use ($projectId): void {
            $q->whereNull('project_id')->orWhere('project_id', $projectId);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PROJECT_ONLY]);
    }

    public function isGlobalReference(): bool
    {
        return $this->project_id === null;
    }

    public function isProjectOverlay(): bool
    {
        return $this->project_id !== null;
    }

    public function name(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->description_fa : $this->description_en;
    }
}
