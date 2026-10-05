<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A hydrotest / pressure-test package made of AFC-released piping lines. */
final class PipingTestPack extends Model
{
    use HasUuid;

    public const DRAFT = 'DRAFT';
    public const READY = 'READY';
    public const TESTED = 'TESTED';
    public const ACCEPTED = 'ACCEPTED';

    protected $fillable = [
        'project_id', 'company_id', 'subsystem_id',
        'code', 'title_en', 'title_fa', 'state',
        'test_pressure', 'test_pressure_unit',
        'ready_at', 'ready_by', 'tested_at', 'tested_by', 'accepted_at', 'accepted_by',
        'notes',
    ];

    protected $casts = [
        'test_pressure' => 'float',
        'ready_at' => 'datetime',
        'tested_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public static function states(): array
    {
        return [self::DRAFT, self::READY, self::TESTED, self::ACCEPTED];
    }

    public static function label(string $state): array
    {
        return match ($state) {
            self::READY => ['fa' => 'آماده تست', 'en' => 'Ready'],
            self::TESTED => ['fa' => 'تست‌شده', 'en' => 'Tested'],
            self::ACCEPTED => ['fa' => 'پذیرفته‌شده', 'en' => 'Accepted'],
            default => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
        };
    }

    public static function nextStates(string $state): array
    {
        return match ($state) {
            self::DRAFT => [self::READY],
            self::READY => [self::TESTED],
            self::TESTED => [self::ACCEPTED],
            default => [],
        };
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function subsystem(): BelongsTo { return $this->belongsTo(Subsystem::class); }
    public function lines(): HasMany { return $this->hasMany(PipingLine::class, 'test_pack_id'); }

    public function scopeOnProject(Builder $query, string $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    public function name(string $lang): string
    {
        return $lang === 'fa'
            ? ($this->title_fa ?: $this->title_en ?: $this->code)
            : ($this->title_en ?: $this->title_fa ?: $this->code);
    }
}
