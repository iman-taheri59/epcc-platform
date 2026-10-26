<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use JsonException;

/** Inherited immutable notice context only. No live ITP/document lookup or new item identity. */
final class InspectionNotificationPrerequisiteStage
{
    public static function fromNotice(object $notice): ?array
    {
        if (($notice->state ?? null) !== 'ISSUED' || ! is_string($notice->issued_snapshot ?? null)) {
            return null;
        }
        try {
            $data = json_decode($notice->issued_snapshot, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($data)) {
            return null;
        }
        foreach (['document_id', 'revision_id', 'stage_id'] as $key) {
            if (! is_string($data[$key] ?? null) || ! Str::isUuid($data[$key])) {
                return null;
            }
        }
        if ($data['stage_id'] !== ($notice->stage_id ?? null)
            || (array_key_exists('project_id', $data) && $data['project_id'] !== ($notice->project_id ?? null))) {
            return null;
        }
        foreach (['document_number', 'revision'] as $key) {
            if (! is_string($data[$key] ?? null) || preg_match('/\S/u', $data[$key]) !== 1) {
                return null;
            }
        }
        if (! is_int($data['stage_sequence'] ?? null) || $data['stage_sequence'] < 1
            || ! in_array($data['point'] ?? null, InspectionNotificationInput::POINTS, true)) {
            return null;
        }
        foreach (['description_en', 'description_fa'] as $key) {
            if (! array_key_exists($key, $data) || (! is_null($data[$key]) && ! is_string($data[$key]))) {
                return null;
            }
        }
        if (preg_match('/\S/u', ($data['description_en'] ?? '').($data['description_fa'] ?? '')) !== 1) {
            return null;
        }

        return array_intersect_key($data, array_flip([
            'document_id', 'document_number', 'revision_id', 'revision',
            'stage_id', 'stage_sequence', 'point', 'description_en', 'description_fa',
        ]));
    }
}
