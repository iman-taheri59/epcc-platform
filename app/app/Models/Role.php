<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** A role template carrying atomic capabilities. Gate 4 (RBAC). */
final class Role extends Model
{
    use HasUuid;

    protected $fillable = ['code', 'name_en', 'name_fa', 'capabilities'];

    protected function casts(): array
    {
        return ['capabilities' => 'array'];
    }

    public function name(string $lang): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    public function grants(string $capability): bool
    {
        foreach ($this->capabilities ?? [] as $c) {
            if ($c === '*' || $c === $capability) {
                return true;
            }

            // Wildcard suffix: "cert.*" grants "cert.approve".
            if (str_ends_with($c, '.*') && str_starts_with($capability, substr($c, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
