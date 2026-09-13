<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\CommentCategory;
use App\Support\CommentDisposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One review comment against one revision (SRS FR-ENG-06).
 */
final class ReviewComment extends Model
{
    use HasUuid;

    protected $table = 'document_review_comments';

    protected $fillable = [
        'document_id', 'revision_id', 'project_id', 'company_id',
        'originator_company_id', 'seq', 'category', 'reference', 'body',
        'raised_by', 'raised_at', 'disposition', 'disposition_note',
        'disposed_by', 'disposed_at',
    ];

    protected $casts = [
        'raised_at' => 'datetime',
        'disposed_at' => 'datetime',
        'seq' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'revision_id');
    }

    /** The company that raised it. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function disposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function isPending(): bool
    {
        return $this->disposition === CommentDisposition::PENDING;
    }

    /** Open AND in a category that stops the drawing being published. */
    public function isBlocking(): bool
    {
        return $this->isPending() && CommentCategory::blocksPublication($this->category);
    }

    public function categoryLabel(string $lang): string
    {
        return CommentCategory::label($this->category, $lang);
    }

    public function categoryCode(string $lang): string
    {
        return CommentCategory::code($this->category, $lang);
    }

    public function dispositionLabel(string $lang): string
    {
        return CommentDisposition::label($this->disposition, $lang);
    }
}
