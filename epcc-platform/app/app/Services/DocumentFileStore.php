<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Support\CdeState;
use App\Support\FileType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Storing and retrieving the actual bytes behind a document revision
 * (SRS FR-DOC-01/02).
 *
 * Separate from DocumentRegister because the register's job is rules about
 * numbering and state, and this one's job is bytes, hashes and disks. They
 * change for different reasons and, when this moves to MinIO for the on-prem
 * build (MKT-EPCC-001), only this file should need to know.
 */
final class DocumentFileStore
{
    public function __construct(private readonly DocumentRegister $register)
    {
    }

    /**
     * Attach an uploaded file to a document's CURRENT revision.
     *
     * @throws ValidationException
     */
    public function attach(
        Document $document,
        User $user,
        UploadedFile $upload,
        string $lang = 'fa',
    ): DocumentFile {
        $revision = $document->currentRevision;

        if ($revision === null) {
            throw ValidationException::withMessages([
                'file' => $lang === 'fa'
                    ? 'این مدرک هیچ بازنگری‌ای ندارد.'
                    : 'This document has no revision.',
            ]);
        }

        $this->assertRevisionAcceptsFiles($revision, $lang);
        $this->assertUploadSucceeded($upload, $lang);

        $extension = strtolower($upload->getClientOriginalExtension());

        if (! FileType::isAllowed($extension)) {
            throw ValidationException::withMessages([
                'file' => $lang === 'fa'
                    ? sprintf(
                        'پسوند «%s» پذیرفته نمی‌شود. مجاز: %s',
                        $extension !== '' ? $extension : '—',
                        implode('، ', FileType::allowedExtensions()),
                    )
                    : sprintf(
                        'Files of type "%s" are not accepted. Allowed: %s',
                        $extension !== '' ? $extension : '—',
                        implode(', ', FileType::allowedExtensions()),
                    ),
            ]);
        }

        $size = (int) $upload->getSize();

        if ($size <= 0) {
            throw ValidationException::withMessages([
                'file' => $lang === 'fa' ? 'فایل خالی است.' : 'The file is empty.',
            ]);
        }

        if ($size > FileType::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => $lang === 'fa'
                    ? sprintf('حجم فایل بیش از %s است.', FileType::humanSize(FileType::MAX_BYTES, 'fa'))
                    : sprintf('The file exceeds %s.', FileType::humanSize(FileType::MAX_BYTES, 'en')),
            ]);
        }

        // Hash the bytes on disk, before anything is copied anywhere. This is
        // both the integrity record and the storage key.
        $sha256 = hash_file('sha256', $upload->getRealPath());

        if ($sha256 === false) {
            throw ValidationException::withMessages([
                'file' => $lang === 'fa' ? 'خواندن فایل ممکن نبود.' : 'The file could not be read.',
            ]);
        }

        $duplicate = DocumentFile::where('revision_id', $revision->id)
            ->where('sha256', $sha256)->first();

        if ($duplicate !== null) {
            // Idempotent rather than an error: this is almost always a
            // double-submit, and failing loudly would teach users to re-upload.
            return $duplicate;
        }

        $disk = config('filesystems.default', 'local');
        $path = $this->storagePath($document, $sha256, $extension);

        return DB::transaction(function () use (
            $document, $revision, $user, $upload, $extension,
            $sha256, $size, $disk, $path
        ): DocumentFile {
            // Write the bytes only if these exact bytes are not already
            // stored - content addressing means an identical re-issue is free.
            if (! Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->putFileAs(
                    dirname($path),
                    $upload,
                    basename($path),
                );
            }

            return DocumentFile::create([
                'document_id' => $document->id,
                'revision_id' => $revision->id,
                'project_id' => $document->project_id,
                'company_id' => $user->company_id,
                'original_name' => FileType::sanitiseName($upload->getClientOriginalName()),
                'storage_path' => $path,
                'disk' => $disk,
                'sha256' => $sha256,
                'size_bytes' => $size,
                // OUR mime, from OUR allow-list, keyed by extension. The
                // client's Content-Type never reaches the database.
                'mime_type' => FileType::mime($extension),
                'extension' => $extension,
                'uploaded_by' => $user->id,
                'uploaded_at' => now(),
            ]);
        });
    }

    /**
     * Detach a file from a revision.
     *
     * The BYTES are deliberately left on disk when any other revision still
     * references the same hash - content addressing means the blob is shared,
     * and deleting it would silently empty someone else's issued revision.
     */
    public function detach(DocumentFile $file, string $lang = 'fa'): void
    {
        $revision = $file->revision;

        if ($revision !== null && ! $this->revisionAcceptsFiles($revision)) {
            throw ValidationException::withMessages([
                'file' => $lang === 'fa'
                    ? 'فایل‌های بازنگری منتشرشده یا بایگانی‌شده قابل حذف نیستند.'
                    : 'Files on a published or archived revision cannot be removed.',
            ]);
        }

        DB::transaction(function () use ($file): void {
            $hash = $file->sha256;
            $disk = $file->disk;
            $path = $file->storage_path;

            $file->delete();

            $stillReferenced = DocumentFile::where('sha256', $hash)
                ->where('disk', $disk)->exists();

            if (! $stillReferenced && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        });
    }

    /**
     * Verify stored bytes still hash to what was recorded (FR-AUD-02).
     *
     * An issued-for-construction drawing that has silently changed on disk is
     * the kind of thing a project only discovers during a claim.
     */
    public function verify(DocumentFile $file): bool
    {
        if (! Storage::disk($file->disk)->exists($file->storage_path)) {
            return false;
        }

        $actual = hash_file(
            'sha256',
            Storage::disk($file->disk)->path($file->storage_path),
        );

        return $actual !== false && hash_equals($file->sha256, $actual);
    }

    /**
     * Content-addressed path. Never contains any user-supplied text.
     *
     *     documents/{project}/{ab}/{cd}/{sha256}.{ext}
     *
     * The two fan-out levels keep directory sizes sane; tens of thousands of
     * entries in one directory is slow on every filesystem worth naming.
     */
    private function storagePath(Document $document, string $sha256, string $extension): string
    {
        return sprintf(
            'documents/%s/%s/%s/%s.%s',
            $document->project_id,
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            $sha256,
            $extension,
        );
    }

    private function revisionAcceptsFiles(DocumentRevision $revision): bool
    {
        // Once a revision is PUBLISHED its content is a matter of record; the
        // correct way to change it is a new revision (FR-ENG-04), which is
        // exactly what DocumentRegister::issueRevision() is for.
        return in_array($revision->cde_state, [CdeState::WIP, CdeState::SHARED], true);
    }

    private function assertRevisionAcceptsFiles(DocumentRevision $revision, string $lang): void
    {
        if ($this->revisionAcceptsFiles($revision)) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => $lang === 'fa'
                ? sprintf(
                    'به بازنگری «%s» نمی‌توان فایل افزود؛ بازنگری جدید صادر کنید.',
                    CdeState::label($revision->cde_state, 'fa'),
                )
                : sprintf(
                    'Files cannot be added to a "%s" revision; issue a new revision instead.',
                    CdeState::label($revision->cde_state, 'en'),
                ),
        ]);
    }

    /**
     * A PHP upload can fail SILENTLY: exceeding upload_max_filesize gives you
     * an UploadedFile that simply is not valid, with no exception anywhere.
     * Reporting "unknown error" for the single most common real-world failure
     * is how a user concludes the product is broken.
     */
    private function assertUploadSucceeded(UploadedFile $upload, string $lang): void
    {
        if ($upload->isValid()) {
            return;
        }

        $limit = ini_get('upload_max_filesize');
        $tooBig = in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

        throw ValidationException::withMessages([
            'file' => $tooBig
                ? ($lang === 'fa'
                    ? sprintf('حجم فایل از حد مجاز سرور (%s) بیشتر است.', $limit)
                    : sprintf('The file is larger than the server limit (%s).', $limit))
                : ($lang === 'fa'
                    ? 'بارگذاری فایل ناقص ماند؛ دوباره تلاش کنید.'
                    : 'The upload did not complete; please try again.'),
        ]);
    }
}
