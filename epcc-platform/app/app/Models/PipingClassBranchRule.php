<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Piping\DnRange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell — or one rectangle of cells — of a PMS branch table (ADR-008 §4).
 *
 * Unlike PipingClassRule, overlap here is legitimate and expected: a branch
 * table is a general rule plus narrower exceptions, so several rules can match
 * one (header, branch) pair and the narrowest wins. The footprint span is
 * therefore load-bearing data, not just a display detail.
 */
final class PipingClassBranchRule extends Model
{
    use HasUuid;

    protected $table = 'piping_class_branch_rules';

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_id',
        'header_range', 'branch_range',
        'component_type', 'value', 'priority', 'source_ref',
    ];

    protected $casts = [
        'value' => 'array',
        'priority' => 'integer',
    ];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class, 'piping_class_id');
    }

    public function headerRange(): DnRange
    {
        return DnRange::fromSql((string) $this->header_range);
    }

    public function branchRange(): DnRange
    {
        return DnRange::fromSql((string) $this->branch_range);
    }

    /**
     * The specificity metric: header span + branch span. A smaller footprint
     * is a more specific rule and beats a general one covering the same cell.
     */
    public function footprint(): int
    {
        return $this->headerRange()->span() + $this->branchRange()->span();
    }
}
