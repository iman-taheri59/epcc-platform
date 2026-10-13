<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends Model
{
    use HasUuid;

    protected $fillable = ['code', 'name_en', 'name_fa', 'tenant_type', 'plan_id'];

    /** Gate 2 of the authorization pipeline reads this. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function name(string $lang): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }
}
