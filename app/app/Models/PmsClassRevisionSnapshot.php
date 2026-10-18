<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable issue-time class content. Mutations are refused by PostgreSQL. */
final class PmsClassRevisionSnapshot extends Model
{
    protected $table = 'pms_class_revision_snapshots';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['specification' => 'array'];

    public function documentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }
}
