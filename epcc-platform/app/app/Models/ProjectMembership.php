<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gate 3 (MEMBERSHIP): which projects a user may enter, and as what. */
final class ProjectMembership extends Model
{
    use HasUuid;

    protected $fillable = [
        'user_id', 'project_id', 'role_codes', 'discipline_scope', 'authority_limit',
    ];

    protected function casts(): array
    {
        return [
            'role_codes' => 'array',
            'discipline_scope' => 'array',
            'authority_limit' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
