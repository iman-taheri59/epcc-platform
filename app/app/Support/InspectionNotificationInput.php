<?php

declare(strict_types=1);

namespace App\Support;

use App\Rules\InspectionActivityEndAfterStart;

/**
 * ADR-042 request-shape contract only, NOT an authorization or issuance service.
 * Consumed by InspectionNotificationService. Revision/stage/company/source/
 * recipient eligibility is resolved there under the original caller RLS.
 */
final class InspectionNotificationInput
{
    public const POINTS = ['H', 'W', 'R', 'M'];

    /** @return array<string, array<int, mixed>> */
    public static function draftRules(): array
    {
        return [
            'notification' => ['required', 'array:itp_revision_id,itp_stage_id,work_reference,activity_start_at,activity_end_at,location,recipient_company_ids,purchase_order_id,notes_en,notes_fa'],
            'notification.itp_revision_id' => ['required', 'uuid'],
            'notification.itp_stage_id' => ['required', 'uuid'],
            'notification.work_reference' => ['required', 'string', 'max:1000'],
            'notification.activity_start_at' => ['bail', 'required', 'string', 'regex:/^(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', 'date_format:Y-m-d\TH:i:sP'],
            'notification.activity_end_at' => ['bail', 'required', 'string', 'regex:/^(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', 'date_format:Y-m-d\TH:i:sP', new InspectionActivityEndAfterStart],
            'notification.location' => ['required', 'string', 'max:2000'],
            'notification.recipient_company_ids' => ['required', 'array', 'min:1', 'max:100'],
            'notification.recipient_company_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'notification.purchase_order_id' => ['nullable', 'uuid'],
            'notification.notes_en' => ['nullable', 'string', 'max:8000'],
            'notification.notes_fa' => ['nullable', 'string', 'max:8000'],
        ];
    }

    /** Same content contract, fixed source; explicit optimistic version. */
    public static function editRules(): array
    {
        $rules = self::draftRules();
        unset($rules['notification.itp_revision_id'], $rules['notification.itp_stage_id']);
        $rules['notification'] = ['required', 'array:version,work_reference,activity_start_at,activity_end_at,location,recipient_company_ids,purchase_order_id,notes_en,notes_fa'];
        $rules['notification.version'] = ['required', 'integer', 'min:1', 'max:2147483645'];

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    public static function issueRules(): array
    {
        return [
            'issuance' => ['required', 'array:version'],
            'issuance.version' => ['required', 'integer', 'min:1'],
        ];
    }
}
