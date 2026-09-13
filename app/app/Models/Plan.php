<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Commercial plan (SRS BR-02). Gate 2 of the ARC §5 pipeline reads this. */
final class Plan extends Model
{
    use HasUuid;

    protected $fillable = [
        'code', 'name_en', 'name_fa', 'max_projects', 'max_users',
        'modules', 'standards_packs',
    ];

    protected function casts(): array
    {
        return ['modules' => 'array', 'standards_packs' => 'array'];
    }

    public function name(string $lang): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    public function hasModule(string $module): bool
    {
        $m = $this->modules ?? [];

        return in_array('*', $m, true) || in_array($module, $m, true);
    }

    public function allowsPack(string $packId): bool
    {
        $p = $this->standards_packs ?? [];

        return in_array('*', $p, true) || in_array($packId, $p, true);
    }
}
