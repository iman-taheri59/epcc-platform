<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A library ITP: the standard plan for one discipline, before any project.
 *
 * Deliberately NOT tenant data - no project_id, no RLS. It is reference
 * material like the pipe-schedule pack. Its `stages` jsonb is a DOCUMENT that
 * gets copied into real rows on use; nothing at runtime reads it, so editing
 * the library can never disturb a project whose ITP is already signed.
 */
final class ItpTemplate extends Model
{
    use HasUuid;

    protected $fillable = [
        'code', 'discipline', 'title_en', 'title_fa',
        'scope_en', 'scope_fa', 'revision_label', 'stages', 'is_active',
    ];

    protected $casts = [
        'stages' => 'array',
        'is_active' => 'boolean',
    ];

    public function title(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }
}
