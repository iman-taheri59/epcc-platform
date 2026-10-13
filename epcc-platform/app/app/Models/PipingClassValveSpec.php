<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The valve layer of one PMS body row — ADR-008 §11.9, manual valves.
 *
 * A PMS valve line says more than "GATE, DN 150, A216 WCB": it says how the
 * valve is built (API 600), what its wetted parts are (Trim 8) and how a person
 * turns it (gear operator). Those three belong to valves and to nothing else,
 * so they hang off the body row rather than widening it.
 *
 * There is no actuator here on purpose. The user's discriminator: if it has an
 * actuator it is a datasheet, if it does not it is a PMS row.
 */
final class PipingClassValveSpec extends Model
{
    use HasUuid;

    protected $table = 'piping_class_valve_specs';

    /** The whole operator vocabulary. Anything powered is a datasheet. */
    public const OPERATORS = ['HANDWHEEL', 'LEVER', 'GEAR', 'CHAINWHEEL'];

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_item_id',
        'construction_standard', 'trim_standard', 'trim_code',
        'operator', 'description_as_written',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PipingClassItem::class, 'piping_class_item_id');
    }

    /** Is the trim resolvable at all? Both halves or neither (DB CHECK). */
    public function hasTrim(): bool
    {
        return $this->trim_standard !== null && $this->trim_code !== null;
    }

    /**
     * 'API 600 Trim 8' — how a trim is written on a real PMS line and on an
     * MTO. Null rather than a half-label when the trim is not stated.
     */
    public function trimLabel(): ?string
    {
        if (! $this->hasTrim()) {
            return null;
        }

        $standard = preg_replace('/^API(\d+)$/', 'API $1', (string) $this->trim_standard);
        $code = (string) $this->trim_code;

        // 'API 600 Trim 8' reads naturally for a numbered chart; 'API 608
        // PTFE' does not take the word Trim, because PTFE is a material and
        // not a trim number.
        return ctype_digit(rtrim($code, 'A'))
            ? "{$standard} Trim {$code}"
            : "{$standard} {$code}";
    }
}
