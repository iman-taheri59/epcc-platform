<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * UUID primary keys.
 *
 * Note: $incrementing / $keyType are set via initializeHasUuid() rather than
 * declared as trait properties. A trait may not redeclare a property that the
 * parent class (Eloquent\Model) already defines - PHP treats differing
 * definitions as a fatal composition conflict.
 */
trait HasUuid
{
    public function initializeHasUuid(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    protected static function bootHasUuid(): void
    {
        static::creating(function ($model): void {
            if (! $model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
