<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentCertificate extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'number', 'serial', 'state',
        'gross_amount', 'price_adjustment', 'retention', 'advance_recovery',
        'tax', 'net_amount', 'consultant_verified', 'measurement_sheets',
        'period_start', 'period_end',
    ];

    protected $casts = [
        'measurement_sheets'  => 'array',
        'consultant_verified' => 'boolean',
        'period_start'        => 'date',
        'period_end'          => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Project this row into the fact shape the standards engine expects.
     *
     * This is the ONLY coupling between Eloquent and the engine: the engine
     * never sees a model, only a plain array. That is what keeps the engine
     * framework-free and lets the TypeScript PWA build the same shape.
     *
     * @return array<string,mixed>
     */
    public function toGateFacts(int|float $approverAuthorityLimit): array
    {
        return [
            'measurementSheets' => $this->measurement_sheets ?? [],
            'verifiedBy'        => $this->consultant_verified ? ['CONSULTANT'] : ['CONTRACTOR'],
            'deductions'        => [
                'retention'       => $this->retention,
                'advanceRecovery' => $this->advance_recovery,
                'priceAdjustment' => $this->price_adjustment,
                'tax'             => $this->tax,
            ],
            'netAmount'              => (float) $this->net_amount,
            'approverAuthorityLimit' => (float) $approverAuthorityLimit,
        ];
    }
}
