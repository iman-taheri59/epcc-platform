<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Security audit of refused requests (SRS NFR-SEC-06). */
final class AuthorizationDenial extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'company_id', 'project_id', 'gate', 'reason', 'capability', 'path',
    ];
}
