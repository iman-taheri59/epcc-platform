<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A system: the FUNCTIONAL group a subsystem belongs to.
 *
 * The physical breakdown (ADR-004) says where a subsystem IS — facility, and
 * optionally the area inside it. This says what it DOES, and the two are
 * deliberately different trees over the same subsystems, because the questions
 * are different: the firefighting pumps sit in one process area and the deluge
 * valves in another, and the client who asks for the firefighting dossier is
 * asking a question no area can answer.
 *
 * A project → systems → subsystems tree therefore hangs off the PROJECT and not
 * off a facility: a system is allowed to span facilities, and ADR-004's own
 * lesson was that a level whose parent is `COALESCE(facility, something else)`
 * is a level every upward query will eventually get wrong.
 *
 * Every subsystem names a system (NOT NULL). A subsystem that names none is a
 * subsystem nobody can commission, and the system is the unit handover is
 * argued about — which is why the ITR compiles for one.
 */
final class System extends Model
{
    use HasUuid;

    protected $table = 'systems';

    protected $fillable = [
        'project_id', 'company_id',
        'code', 'name_en', 'name_fa',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subsystems(): HasMany
    {
        return $this->hasMany(Subsystem::class);
    }

    public function name(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    /**
     * Systems are numbered per project and a system may span facilities, so the
     * code alone is unambiguous — unlike a subsystem's, which needs its
     * facility (see Subsystem::fullCode()).
     */
    public function fullCode(): string
    {
        return $this->code;
    }
}
