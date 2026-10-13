<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\ReviewComment;
use App\Models\User;
use App\Support\CdeState;
use App\Support\CommentCategory;
use App\Support\CommentDisposition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The review & comment cycle (SRS FR-ENG-06, journey 3).
 *
 * The cycle this implements:
 *
 *   EPC shares Rev A (S2)  ->  PMC and the owner comment IN PARALLEL
 *   ->  EPC dispositions every comment  ->  publication is unblocked
 *   ->  EPC issues Rev B  ->  round 2 if anything still blocks
 *
 * The one rule that gives the whole module its value:
 * **A drawing carrying an open blocking comment cannot be published.**
 * Without that, a comment sheet is a suggestion box.
 */
final class ReviewCycle
{
    /**
     * Raise a comment against a revision.
     *
     * @throws ValidationException
     */
    public function comment(
        Document $document,
        User $reviewer,
        string $category,
        string $body,
        ?string $reference = null,
        string $lang = 'fa',
    ): ReviewComment {
        $revision = $document->currentRevision;

        if ($revision === null) {
            throw ValidationException::withMessages([
                'body' => $lang === 'fa' ? 'این مدرک بازنگری ندارد.' : 'This document has no revision.',
            ]);
        }

        // Only a SHARED revision is under review. Commenting on WIP means
        // reviewing something the author has not offered yet; commenting on
        // PUBLISHED means the review happened and the answer is a new
        // revision, not a late comment.
        if ($revision->cde_state !== CdeState::SHARED) {
            throw ValidationException::withMessages([
                'body' => $lang === 'fa'
                    ? sprintf(
                        'تنها مدارک «%s» قابل بررسی هستند؛ وضعیت فعلی «%s» است.',
                        CdeState::label(CdeState::SHARED, 'fa'),
                        CdeState::label($revision->cde_state, 'fa'),
                    )
                    : sprintf(
                        'Only "%s" revisions are under review; this one is "%s".',
                        CdeState::label(CdeState::SHARED, 'en'),
                        CdeState::label($revision->cde_state, 'en'),
                    ),
            ]);
        }

        if (! CommentCategory::isValid($category)) {
            throw ValidationException::withMessages([
                'category' => $lang === 'fa' ? 'دستهٔ نظر معتبر نیست.' : 'That comment category is not valid.',
            ]);
        }

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => $lang === 'fa' ? 'متن نظر را وارد کنید.' : 'Enter the comment text.',
            ]);
        }

        // The author reviewing its own drawing is not a review. Self-approval
        // is exactly the control an owner is paying for.
        if ($reviewer->company_id === $document->company_id) {
            throw ValidationException::withMessages([
                'body' => $lang === 'fa'
                    ? 'شرکت تهیه‌کننده نمی‌تواند مدرک خود را بررسی کند.'
                    : 'The originating company cannot review its own document.',
            ]);
        }

        return DB::transaction(function () use ($document, $revision, $reviewer, $category, $body, $reference): ReviewComment {
            // Serialise sequence allocation against the PARENT row. Parallel
            // review is a stated requirement, so two reviewers commenting in
            // the same instant is the normal case, not a corner case, and the
            // unique index on (revision_id, seq) would otherwise reject one of
            // them with a database error.
            //
            // The lock is taken on document_revisions, NOT on an aggregate:
            // PostgreSQL refuses `FOR UPDATE` alongside max() outright
            // ("FOR UPDATE is not allowed with aggregate functions").
            DocumentRevision::whereKey($revision->id)->lockForUpdate()->first();

            $next = 1 + (int) ReviewComment::query()
                ->where('revision_id', $revision->id)
                ->max('seq');

            return ReviewComment::create([
                'document_id' => $document->id,
                'revision_id' => $revision->id,
                'project_id' => $document->project_id,
                'company_id' => $reviewer->company_id,
                'originator_company_id' => $document->company_id,
                'seq' => $next,
                'category' => $category,
                'reference' => $reference,
                'body' => $body,
                'raised_by' => $reviewer->id,
                'raised_at' => now(),
                // Set explicitly rather than leaning on the column default.
                // A DB default is applied by PostgreSQL and NOT reflected back
                // into the model, so the in-memory object had disposition=NULL
                // while the row said 'PENDING' - and isPending()/isBlocking()
                // therefore returned the WRONG answer on a freshly created
                // comment. Silent disagreement between object and row.
                'disposition' => CommentDisposition::PENDING,
            ]);
        });
    }

    /**
     * Answer a comment (FR-ENG-06: disposition with justification).
     *
     * @throws ValidationException
     */
    public function dispose(
        ReviewComment $comment,
        User $user,
        string $disposition,
        ?string $note,
        string $lang = 'fa',
    ): ReviewComment {
        if (! CommentDisposition::isSelectable($disposition)) {
            throw ValidationException::withMessages([
                'disposition' => $lang === 'fa' ? 'پاسخ معتبر نیست.' : 'That disposition is not valid.',
            ]);
        }

        // Only the company that owns the deliverable answers. A reviewer
        // dispositioning its own comment would be marking its own homework.
        if ($user->company_id !== $comment->originator_company_id) {
            throw ValidationException::withMessages([
                'disposition' => $lang === 'fa'
                    ? 'تنها شرکت تهیه‌کنندهٔ مدرک می‌تواند به نظرها پاسخ دهد.'
                    : 'Only the originating company can respond to review comments.',
            ]);
        }

        $note = $note !== null ? trim($note) : null;

        if (CommentDisposition::requiresJustification($disposition) && ($note === null || $note === '')) {
            // "Rejected" with no reason is how a review cycle becomes a
            // dispute, and that dispute is eventually read by a lawyer.
            throw ValidationException::withMessages([
                'disposition_note' => $lang === 'fa'
                    ? 'برای رد کردن یک نظر، ذکر دلیل الزامی است.'
                    : 'Rejecting a comment requires a written justification.',
            ]);
        }

        $comment->update([
            'disposition' => $disposition,
            'disposition_note' => $note,
            'disposed_by' => $user->id,
            'disposed_at' => now(),
        ]);

        return $comment->refresh();
    }

    /**
     * Comments that currently prevent publication.
     *
     * @return Collection<int,ReviewComment>
     */
    public function blockers(DocumentRevision $revision): Collection
    {
        return $revision->comments()
            ->where('disposition', CommentDisposition::PENDING)
            ->whereIn('category', CommentCategory::blocking())
            ->orderBy('seq')
            ->get();
    }

    /**
     * The gate. Called by DocumentRegister before WIP/SHARED -> PUBLISHED.
     *
     * @throws ValidationException
     */
    public function assertPublishable(DocumentRevision $revision, string $lang = 'fa'): void
    {
        $blockers = $this->blockers($revision);

        if ($blockers->isEmpty()) {
            return;
        }

        // Name the comments. "Blocked by 3 comments" sends the user hunting;
        // "comments 2, 5, 7" sends them to the right rows.
        $numbers = $blockers->pluck('seq')
            ->map(fn (int $n) => $lang === 'fa'
                ? \Morilog\Jalali\CalendarUtils::convertNumbers((string) $n)
                : (string) $n)
            ->implode($lang === 'fa' ? '، ' : ', ');

        throw ValidationException::withMessages([
            'state' => $lang === 'fa'
                ? sprintf('تا پاسخ‌دادن به نظرهای %s امکان انتشار وجود ندارد.', $numbers)
                : sprintf('Cannot publish until comments %s are dispositioned.', $numbers),
        ]);
    }

    /**
     * Closure statistics for the sheet header (FR-ENG-06).
     *
     * @return array{total:int,pending:int,blocking:int,accepted:int,rejected:int,noted:int,closure:int}
     */
    public function statistics(DocumentRevision $revision): array
    {
        $comments = $revision->relationLoaded('comments')
            ? $revision->comments
            : $revision->comments()->get();

        $total = $comments->count();
        $pending = $comments->where('disposition', CommentDisposition::PENDING)->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'blocking' => $comments->filter(fn (ReviewComment $c) => $c->isBlocking())->count(),
            'accepted' => $comments->where('disposition', CommentDisposition::ACCEPTED)->count(),
            'rejected' => $comments->where('disposition', CommentDisposition::REJECTED)->count(),
            'noted' => $comments->where('disposition', CommentDisposition::NOTED)->count(),
            // Percentage closed. 100 for a revision with no comments: nothing
            // outstanding is the honest reading, and it keeps the bar from
            // showing an alarming 0% on a clean drawing.
            'closure' => $total === 0 ? 100 : (int) round((($total - $pending) / $total) * 100),
        ];
    }
}
