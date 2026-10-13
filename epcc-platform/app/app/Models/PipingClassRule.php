<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Piping\PipingRuleKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PMS rule, for ONE size (ADR-008 §3, as corrected by the user).
 *
 * There are no bands. The user's instruction was that each size a class
 * carries gets its own row, because that is how a PMS is read and transcribed:
 * pick a diameter, then pick its schedule. The four-inch boundary problem the
 * range design existed to solve cannot arise when there is no boundary.
 *
 * Both dn and nps are stored. dn is the integer the lookup keys on; nps is the
 * label the engineer typed, kept because "1 1/4" cannot be reconstructed from
 * 32 without a lookup and a rule should be readable without one.
 */
final class PipingClassRule extends Model
{
    use HasUuid;

    protected $table = 'piping_class_rules';

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_id',
        'rule_kind', 'dn', 'nps', 'value',
        'note_en', 'note_fa', 'source_ref',
    ];

    protected $casts = [
        'value' => 'array',
        'dn' => 'integer',
    ];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class, 'piping_class_id');
    }

    /**
     * What this rule resolves to — the schedule, the end type, whatever its
     * kind declares. Null means the row was stored with a payload that does
     * not match its kind, which the service refuses to create.
     */
    public function resolvedValue(): string|int|float|null
    {
        $key = PipingRuleKind::valueKey((string) $this->rule_kind);

        if ($key === null) {
            return null;
        }

        return $this->value[$key] ?? null;
    }
}
