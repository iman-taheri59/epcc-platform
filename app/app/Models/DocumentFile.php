<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\FileType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to one revision of a document (SRS FR-DOC-01).
 *
 * `storage_path` is content-addressed and never contains user input; see the
 * migration and DocumentFileStore for why.
 */
final class DocumentFile extends Model
{
    use HasUuid;

    protected $fillable = [
        'document_id', 'revision_id', 'project_id', 'company_id',
        'original_name', 'storage_path', 'disk', 'sha256', 'size_bytes',
        'mime_type', 'extension', 'uploaded_by', 'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size_bytes' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'revision_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(string $lang = 'fa'): string
    {
        return FileType::humanSize($this->size_bytes, $lang);
    }

    public function typeLabel(string $lang = 'fa'): string
    {
        return FileType::label($this->extension, $lang);
    }

    /** May the browser render this inline, or must it be downloaded? */
    public function isInlineViewable(): bool
    {
        return FileType::isInlineViewable($this->extension);
    }

    /** First 12 hex characters - enough to quote in a transmittal or a claim. */
    public function shortHash(): string
    {
        return substr($this->sha256, 0, 12);
    }
}
