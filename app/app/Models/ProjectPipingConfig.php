<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Numbering\NumberPattern;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-project piping configuration: the line-number pattern and the small
 * project registers (ADR-006 §4, §11.5).
 *
 * The authority for a line number is the project's PMS, not the standards
 * pack, so the format lives here as data. Two projects on the same ISO pack
 * number their lines differently and both are right.
 */
final class ProjectPipingConfig extends Model
{
    use HasUuid;

    protected $table = 'project_piping_config';

    protected $fillable = [
        'project_id', 'company_id',
        'line_number_pattern', 'services', 'coatings', 'insulation_types',
    ];

    protected $casts = [
        'line_number_pattern' => 'array',
        'services' => 'array',
        'coatings' => 'array',
        'insulation_types' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The stored pattern, as the typed object the engine works with. */
    public function pattern(): NumberPattern
    {
        return NumberPattern::fromArray((array) $this->line_number_pattern);
    }

    /**
     * The default pattern for a new project: the format the user described in
     * ADR-006 §4, which is the common Iranian EPCC shape.
     *
     * Supplied as a starting point that an engineering lead edits - not as a
     * hardcoded rule. Coating is optional because plenty of lines have none.
     *
     * @return array{separator: string, tokens: list<array<string, mixed>>}
     */
    public static function defaultPattern(): array
    {
        return [
            'separator' => '-',
            'tokens' => [
                ['token' => 'size', 'source' => 'attribute', 'attribute' => 'nominal_size'],
                ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
                ['token' => 'class', 'source' => 'attribute', 'attribute' => 'piping_class'],
                ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
                ['token' => 'sequence', 'source' => 'counter', 'width' => 3],
                ['token' => 'coating', 'source' => 'attribute', 'attribute' => 'coating_code', 'optional' => true],
            ],
        ];
    }
}
