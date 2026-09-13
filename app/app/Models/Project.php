<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Project extends Model
{
    use HasUuid;

    protected $fillable = ['code', 'name_en', 'name_fa', 'standards_pack', 'owner_company_id'];

    public function ownerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    public function name(string $lang): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }
}
