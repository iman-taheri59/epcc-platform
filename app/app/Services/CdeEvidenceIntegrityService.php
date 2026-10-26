<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CdeState;
use App\Support\FileType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Exact visible CDE tuple + retained local bytes. NOT admission/approval. */
final class CdeEvidenceIntegrityService
{
    public function verify(string $project, string $document, string $revision, string $file, string $expectedSha256): array
    {
        $result = ['status' => 'BLOCKED', 'integrity_verified' => false, 'ready_to_admit' => false,
            'admission_state' => 'UNADMITTED', 'ready' => false, 'gross' => null, 'source' => null, 'findings' => []];
        foreach ([$project, $document, $revision, $file] as $id) {
            if (! Str::isUuid($id)) {
                $result['findings'] = ['INVALID_EXACT_REFERENCE'];
                return $result;
            }
        }
        [$project, $document, $revision, $file] = array_map('strtolower', [$project, $document, $revision, $file]);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            $result['findings'] = ['INVALID_EXPECTED_DIGEST'];
            return $result;
        }
        return DB::transaction(function () use ($project, $document, $revision, $file, $expectedSha256, $result): array {
            // One SQL statement, normal invoker RLS on all three sources. Never
            // use currentRevision, caller models, client disk/path or tenant switches.
            $lookup = static fn () => DB::table('document_files as f')
                ->join('document_revisions as r', function ($j): void {
                    $j->on('r.id', '=', 'f.revision_id')->on('r.document_id', '=', 'f.document_id')->on('r.project_id', '=', 'f.project_id');
                })->join('documents as d', function ($j): void {
                    $j->on('d.id', '=', 'r.document_id')->on('d.project_id', '=', 'r.project_id');
                })->where('f.project_id', $project)->where('d.id', $document)->where('r.id', $revision)->where('f.id', $file)
                ->select(['f.id', 'f.sha256', 'f.size_bytes', 'f.disk', 'f.storage_path', 'f.extension',
                    'r.revision', 'r.cde_state', 'r.suitability', 'r.issued_by', 'r.issued_at', 'd.number'])->first();
            $row = $lookup();
            if ($row === null) {
                $result['findings'] = ['EXACT_SOURCE_NOT_VISIBLE_OR_MISMATCHED'];
                return $result;
            }
            if (! hash_equals($expectedSha256, $row->sha256)) {
                $result['findings'] = ['EXPECTED_DIGEST_MISMATCH'];
                return $result;
            }
            // Bounded first adapter: no S3/HTTP/URI fetch or arbitrary disk.
            if ($row->disk !== 'local' || config('filesystems.disks.local.driver') !== 'local') {
                $result['findings'] = ['STORAGE_ADAPTER_UNSUPPORTED'];
                return $result;
            }
            $canonical = sprintf('documents/%s/%s/%s/%s.%s', $project,
                substr($expectedSha256, 0, 2), substr($expectedSha256, 2, 2), $expectedSha256, $row->extension);
            if (! FileType::isAllowed($row->extension) || $row->storage_path !== $canonical) {
                $result['findings'] = ['NONCANONICAL_STORAGE_REFERENCE'];
                return $result;
            }
            if ((int) $row->size_bytes <= 0 || (int) $row->size_bytes > FileType::MAX_BYTES) {
                $result['findings'] = ['RECORDED_SIZE_OUT_OF_BOUNDS'];
                return $result;
            }
            $stream = null;
            try {
                $stream = Storage::disk('local')->readStream($canonical);
                if (! is_resource($stream)) { throw new \RuntimeException('Unreadable evidence'); }
                $ctx = hash_init('sha256'); $count = 0;
                while (! feof($stream)) {
                    $bytes = fread($stream, 65536);
                    if ($bytes === false || ($bytes === '' && ! feof($stream))) { throw new \RuntimeException('Failed read'); }
                    $count += strlen($bytes);
                    if ($count > (int) $row->size_bytes || $count > FileType::MAX_BYTES) {
                        $result['findings'] = ['BLOB_SIZE_MISMATCH'];
                        return $result;
                    }
                    hash_update($ctx, $bytes);
                }
                $actual = hash_final($ctx);
            } catch (\Exception) {
                $result['findings'] = ['BLOB_NOT_READABLE'];
                return $result;
            } finally {
                if (is_resource($stream)) { fclose($stream); }
            }
            if ($count !== (int) $row->size_bytes) {
                $result['findings'] = ['BLOB_SIZE_MISMATCH'];
                return $result;
            }
            if (! hash_equals($expectedSha256, $actual)) {
                $result['findings'] = ['BLOB_DIGEST_MISMATCH'];
                return $result;
            }
            if ($lookup() != $row) {
                $result['findings'] = ['SOURCE_METADATA_CHANGED_DURING_READ'];
                return $result;
            }
            $result['status'] = 'BYTES_VERIFIED';
            $result['integrity_verified'] = true;
            $result['source'] = ['project_id' => $project, 'document_id' => $document, 'revision_id' => $revision,
                'file_id' => $file, 'sha256' => $actual, 'size_bytes' => $count, 'document_number' => $row->number,
                'revision_code' => $row->revision, 'cde_state' => $row->cde_state, 'suitability' => $row->suitability,
                'issued_by' => $row->issued_by, 'issued_at' => $row->issued_at];
            $result['findings'] = ['ENGINEERING_AUTHORITY_NOT_ESTABLISHED', 'RETENTION_AND_REVOCATION_NOT_ESTABLISHED'];
            if (! in_array($row->cde_state, [CdeState::PUBLISHED, CdeState::ARCHIVED], true)) {
                $result['findings'][] = 'REVISION_CONTENT_MUTABLE';
            }
            return $result;
        });
    }
}
