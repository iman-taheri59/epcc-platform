<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** GET-only prerequisite projection filters; never a writer or source-access selector. */
final class InspectionPrerequisiteFilters
{
    public static function fromQuery(array $query): array
    {
        $keys = ['prerequisite_state', 'prerequisite_company', 'prerequisite_notice', 'prerequisite_company_q', 'prerequisite_notice_state'];
        $input = array_intersect_key($query, array_flip($keys));
        foreach ($input as $key => &$value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw ValidationException::withMessages([$key => __('inspection_notifications.filter_invalid')]);
            }
            if (is_string($value)) {
                $value = trim($value, " \t\n\r\v\f");
            }
        }
        unset($value);
        $d = Validator::make($input, [
            'prerequisite_notice_state' => ['bail', 'nullable', 'string', Rule::in(['ACTIVE', 'CANCELLED'])],
            'prerequisite_state' => ['bail', 'nullable', 'string', Rule::in(['OPEN', 'REPORTED', 'FULFILLED', 'WITHDRAWN'])],
            'prerequisite_company' => ['bail', 'nullable', 'string', 'uuid'],
            'prerequisite_company_q' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[\s\S]*\z/u', 'not_regex:/\x00/u'],
            'prerequisite_notice' => ['bail', 'nullable', 'string', 'max:100', 'regex:/\A[\s\S]*\z/u', 'not_regex:/\x00/u'],
        ])->validate();

        return [
            'prerequisite_state' => $d['prerequisite_state'] ?? '',
            'prerequisite_company' => strtolower($d['prerequisite_company'] ?? ''),
            'prerequisite_notice' => $d['prerequisite_notice'] ?? '',
            'prerequisite_company_q' => $d['prerequisite_company_q'] ?? '',
            'prerequisite_notice_state' => $d['prerequisite_notice_state'] ?? '',
        ];
    }
}
