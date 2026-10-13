<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Project;
use App\Models\ReviewComment;
use App\Services\DocumentFileStore;
use App\Services\DocumentRegister;
use App\Services\ReviewCycle;
use App\Support\AuthorizationPipeline;
use App\Support\CdeState;
use App\Support\CommentCategory;
use App\Support\CommentDisposition;
use App\Support\FileType;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * Document register / CDE (SRS FR-ENG-01/02/03/04).
 *
 * Which rows appear is decided by PostgreSQL RLS, exactly as on the
 * certificate screens: the controller binds the tenant and the project scope
 * and then simply queries. It never adds a `where company_id` of its own,
 * because a filter written here is a filter that can be forgotten.
 */
final class DocumentController extends Controller
{
    /** Disciplines offered by the register (SRS §6.3). */
    private const DISCIPLINES = [
        'CIVIL', 'STRUCT', 'ARCH', 'MECH', 'PIPING', 'PROCESS',
        'ELEC', 'INST', 'HVAC', 'HSE', 'PIPELINE', 'TELECOM',
    ];

    /** Deliverable types (FR-ENG-01). */
    private const DOC_TYPES = ['DWG', 'SPEC', 'DS', 'CALC', 'MTO', 'RPT', 'PID', 'ITP', 'PMS'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly DocumentRegister $register,
        private readonly DocumentFileStore $files,
        private readonly ReviewCycle $review,
    ) {
    }

    /** The register for one project. */
    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.view', $project, $lang);

        [$documents, $counts] = $this->tenant->runAs(
            $user->company_id,
            function (): array {
                // `currentRevision.files` is eager-loaded INSIDE runAs():
                // document_files is an RLS table, so a lazy load after the
                // scope closes returns an empty collection - the attachments
                // would silently vanish from the register.
                $documents = Document::with([
                    'currentRevision.files',
                    // Eager-loaded inside runAs() for the same reason as the
                    // files: a lazy load after the scope closes comes back
                    // empty, and an empty comment sheet looks like a clean one.
                    'currentRevision.comments',
                    'company',
                ])
                    ->orderBy('discipline')->orderBy('sequence')
                    ->get();

                // Counted from the rows RLS actually returned, so the summary
                // can never disagree with the table beneath it.
                $counts = [];

                foreach (CdeState::all() as $state) {
                    $counts[$state] = $documents
                        ->filter(fn (Document $d) => $d->currentRevision?->cde_state === $state)
                        ->count();
                }

                return [$documents, $counts];
            },
            $project->id,
        );

        return view('documents.index', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'documents' => $documents,
            'counts' => $counts,
            'states' => CdeState::all(),
            'disciplines' => self::DISCIPLINES,
            'docTypes' => self::DOC_TYPES,
            'canCreate' => $this->authz->allows($user, 'doc.create', $project),
            'canIssue' => $this->authz->allows($user, 'doc.issue', $project),
            'allowedExtensions' => FileType::allowedExtensions(),
            'maxUploadLabel' => FileType::humanSize(FileType::MAX_BYTES, $lang),
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Register a new deliverable. */
    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.create', $project, $lang);

        $data = $request->validate([
            'discipline' => ['required', 'string', 'in:'.implode(',', self::DISCIPLINES)],
            'doc_type' => ['required', 'string', 'in:'.implode(',', self::DOC_TYPES)],
            'title_en' => ['required', 'string', 'max:512'],
            'title_fa' => ['required', 'string', 'max:512'],
            'revision' => ['nullable', 'string', 'max:16'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            fn () => $this->register->register($project, $user, $data),
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/documents?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'مدرک ثبت شد.' : 'Document registered.');
    }

    /** Move a document along the CDE state machine, or issue a revision. */
    public function update(Request $request, string $projectCode, string $documentId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        // Changing state IS the act of issuing; it needs the write capability.
        $this->authorize($request, $user, 'doc.issue', $project, $lang);

        $action = $request->input('action');

        $this->tenant->runAs(
            $user->company_id,
            function () use ($request, $documentId, $user, $action, $lang): void {
                // Fetched INSIDE the tenant scope: RLS decides whether this
                // document is visible at all, so a foreign id 404s naturally
                // instead of needing a hand-written ownership check.
                $document = Document::with('currentRevision')->findOrFail($documentId);

                if ($action === 'revise') {
                    $this->register->issueRevision(
                        $document,
                        $user,
                        (string) $request->input('revision'),
                        $request->input('remarks'),
                        $lang,
                    );

                    return;
                }

                $this->register->transition(
                    $document,
                    $user,
                    (string) $request->input('cde_state'),
                    $request->input('suitability') ?: null,
                    $lang,
                );
            },
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/documents?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'وضعیت به‌روزرسانی شد.' : 'Status updated.');
    }

    /**
     * Attach a file to a document's current revision (FR-DOC-01).
     */
    public function upload(Request $request, string $projectCode, string $documentId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        // Attaching a file to a deliverable IS issuing content, so it needs
        // the write capability - `doc.view` must never be enough.
        $this->authorize($request, $user, 'doc.issue', $project, $lang);

        // Note there is no `mimes:` rule here. Laravel's mime validation trusts
        // a guessed type; the allow-list in FileType is the real gate, and it
        // lives in the service so the future API and importer share it.
        $request->validate(['file' => ['required', 'file']]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($request, $documentId, $user, $lang): void {
                $document = Document::with('currentRevision')->findOrFail($documentId);

                $this->files->attach($document, $user, $request->file('file'), $lang);
            },
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/documents?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'فایل پیوست شد.' : 'File attached.');
    }

    /**
     * Stream a stored file back (FR-DOC-02).
     *
     * Files live OUTSIDE the public root and are only ever served through this
     * action, so every download passes authentication, entitlement, membership
     * and RLS. A public/storage symlink would hand the whole register to anyone
     * who can guess a path - on a multi-company project that is a contract
     * breach, not just a bug.
     */
    public function download(Request $request, string $projectCode, string $documentId, string $fileId): StreamedResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.view', $project, $lang);

        /** @var DocumentFile $file */
        $file = $this->tenant->runAs(
            $user->company_id,
            fn () => DocumentFile::where('document_id', $documentId)->findOrFail($fileId),
            $project->id,
        );

        $disk = \Illuminate\Support\Facades\Storage::disk($file->disk);

        abort_unless($disk->exists($file->storage_path), 404);

        // `inline` only for types a browser renders safely. Everything else is
        // an attachment: inlining an arbitrary upload is how a stored file
        // becomes stored XSS.
        $disposition = $request->query('download') === '1' || ! $file->isInlineViewable()
            ? 'attachment'
            : 'inline';

        return $disk->response(
            $file->storage_path,
            FileType::sanitiseName($file->original_name),
            [
                'Content-Type' => $file->mime_type,
                // Belt and braces: even for inline types, forbid the browser
                // from sniffing its way to a different, executable type.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; object-src 'none'",
            ],
            $disposition,
        );
    }

    /** Remove an attachment from a revision that is still open. */
    public function deleteFile(Request $request, string $projectCode, string $documentId, string $fileId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.issue', $project, $lang);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($documentId, $fileId, $lang): void {
                $file = DocumentFile::with('revision')
                    ->where('document_id', $documentId)->findOrFail($fileId);

                $this->files->detach($file, $lang);
            },
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/documents?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'فایل حذف شد.' : 'File removed.');
    }

    /**
     * The comment sheet for one document (FR-ENG-06).
     */
    public function review(Request $request, string $projectCode, string $documentId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.view', $project, $lang);

        [$document, $comments, $stats] = $this->tenant->runAs(
            $user->company_id,
            function () use ($documentId): array {
                $document = Document::with(['currentRevision', 'company'])->findOrFail($documentId);
                $revision = $document->currentRevision;

                $comments = $revision
                    ? $revision->comments()->with(['reviewer', 'raiser', 'disposer'])->get()
                    : collect();

                // Set the relation so statistics() does not re-query outside
                // this scope.
                if ($revision !== null) {
                    $revision->setRelation('comments', $comments);
                }

                return [
                    $document,
                    $comments,
                    $revision ? $this->review->statistics($revision) : null,
                ];
            },
            $project->id,
        );

        $revision = $document->currentRevision;
        $isOriginator = $user->company_id === $document->company_id;

        return view('documents.review', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'document' => $document,
            'revision' => $revision,
            'comments' => $comments,
            'stats' => $stats,
            'categories' => CommentCategory::all(),
            'dispositions' => CommentDisposition::selectable(),
            // A reviewer comments; the originator answers. Nobody does both,
            // and the screen shows each party only what it can act on.
            'canComment' => ! $isOriginator
                && $revision?->cde_state === CdeState::SHARED
                && $this->authz->allows($user, 'doc.review', $project),
            'canDispose' => $isOriginator && $this->authz->allows($user, 'doc.issue', $project),
            'isOriginator' => $isOriginator,
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Raise a review comment. */
    public function comment(Request $request, string $projectCode, string $documentId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.review', $project, $lang);

        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', CommentCategory::all())],
            'body' => ['required', 'string', 'max:4000'],
            'reference' => ['nullable', 'string', 'max:128'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($documentId, $user, $data, $lang): void {
                $document = Document::with('currentRevision')->findOrFail($documentId);

                $this->review->comment(
                    $document,
                    $user,
                    $data['category'],
                    $data['body'],
                    $data['reference'] ?? null,
                    $lang,
                );
            },
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/documents/{$documentId}/review?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'نظر ثبت شد.' : 'Comment recorded.');
    }

    /** Answer a review comment. */
    public function dispose(Request $request, string $projectCode, string $documentId, string $commentId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.issue', $project, $lang);

        $data = $request->validate([
            'disposition' => ['required', 'string', 'in:'.implode(',', CommentDisposition::selectable())],
            'disposition_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($documentId, $commentId, $user, $data, $lang): void {
                $comment = ReviewComment::where('document_id', $documentId)->findOrFail($commentId);

                $this->review->dispose(
                    $comment,
                    $user,
                    $data['disposition'],
                    $data['disposition_note'] ?? null,
                    $lang,
                );
            },
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/documents/{$documentId}/review?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'پاسخ ثبت شد.' : 'Disposition recorded.');
    }

    /**
     * Resolve a project the user may actually see.
     *
     * Mirrors the certificate screens: a project the acting company does not
     * participate in must 404, not 403 - a 403 confirms the project exists.
     */
    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || \DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        // Participation is the commercial fact; membership is the personal
        // grant. Both are required (ARC §5.4).
        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function authorize(Request $request, $user, string $capability, Project $project, string $lang): void
    {
        $denial = $this->authz->check($user, $capability, $project);

        if ($denial !== null) {
            abort(403, $denial->message($lang));
        }
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'fa';
    }

    private function today(string $lang): string
    {
        $d = Jalalian::now()->format('Y/m/d');

        return $lang === 'fa' ? CalendarUtils::convertNumbers($d) : $d;
    }
}
