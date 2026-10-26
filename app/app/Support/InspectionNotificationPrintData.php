<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Pure validation of issued data. Never repairs history or resolves live source metadata. */
final class InspectionNotificationPrintData
{
    public static function snapshot(object $notice): array
    {
        if ($notice->state !== 'ISSUED') {
            throw ValidationException::withMessages(['notification' => __('inspection_notifications.pdf_issued_only')]);
        }
        try {
            $snapshot = json_decode($notice->issued_snapshot ?? '', true, 512, JSON_THROW_ON_ERROR);
            $recipientIds = json_decode($notice->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::invalid();
        }
        if (! is_array($snapshot) || ! is_array($recipientIds) || $recipientIds === []
            || ! is_string($notice->number) || trim($notice->number) === '' || $notice->issued_at === null) {
            self::invalid();
        }
        foreach (['document_id', 'revision_id', 'stage_id', 'issuer_id'] as $key) {
            if (! is_string($snapshot[$key] ?? null) || ! Str::isUuid($snapshot[$key])) {
                self::invalid();
            }
        }
        if ($snapshot['stage_id'] !== $notice->stage_id || $snapshot['issuer_id'] !== $notice->issued_by) {
            self::invalid();
        }
        foreach (['document_number', 'revision', 'issuer_name', 'sender_code', 'sender_name_en', 'sender_name_fa', 'cde_state'] as $key) {
            if (! is_string($snapshot[$key] ?? null) || trim($snapshot[$key]) === '') {
                self::invalid();
            }
        }
        if (! in_array($snapshot['point'] ?? null, InspectionNotificationInput::POINTS, true)
            || ! is_int($snapshot['stage_sequence'] ?? null) || $snapshot['stage_sequence'] < 1
            || ! array_key_exists('suitability', $snapshot) || (! is_null($snapshot['suitability']) && ! is_string($snapshot['suitability']))) {
            self::invalid();
        }
        foreach (['description_en', 'description_fa'] as $key) {
            if (! array_key_exists($key, $snapshot) || (! is_null($snapshot[$key]) && ! is_string($snapshot[$key]))) {
                self::invalid();
            }
        }
        if (trim(($snapshot['description_en'] ?? '').($snapshot['description_fa'] ?? '')) === '') {
            self::invalid();
        }
        if (! is_array($snapshot['recipients'] ?? null) || $snapshot['recipients'] === []) {
            self::invalid();
        }
        $capturedIds = [];
        foreach ($snapshot['recipients'] as $recipient) {
            if (! is_array($recipient) || ! is_string($recipient['id'] ?? null) || ! Str::isUuid($recipient['id'])) {
                self::invalid();
            }
            foreach (['code', 'name_en', 'name_fa'] as $key) {
                if (! is_string($recipient[$key] ?? null) || trim($recipient[$key]) === '') {
                    self::invalid();
                }
            }
            $capturedIds[] = $recipient['id'];
        }
        foreach ($recipientIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                self::invalid();
            }
        }
        sort($capturedIds);
        sort($recipientIds);
        if ($capturedIds !== $recipientIds || count(array_unique($capturedIds)) !== count($capturedIds)) {
            self::invalid();
        }
        // New issues capture project names. Older issued records remain untouched:
        // an absent group is explicit unknown, never a join to today's project name.
        $projectKeys = ['project_id', 'project_code', 'project_name_en', 'project_name_fa'];
        $present = array_intersect($projectKeys, array_keys($snapshot));
        if ($present !== []) {
            if (count($present) !== count($projectKeys)) {
                self::invalid();
            }
            foreach ($projectKeys as $key) {
                if (! is_string($snapshot[$key]) || trim($snapshot[$key]) === '') {
                    self::invalid();
                }
            }
            if ($snapshot['project_id'] !== $notice->project_id) {
                self::invalid();
            }
        }
        $snapshot['has_project_snapshot'] = $present !== [];
        $snapshot['has_activity_window'] = InspectionNotificationSchedule::hasWindow($notice);

        return $snapshot;
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['notification' => __('inspection_notifications.pdf_invalid_snapshot')]);
    }
}
