<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project document cited by one ITP stage: a specification, a drawing, a
 * procedure, or the inspection report form itself.
 *
 * Why this is a foreign key and not a string column: an external standard
 * (`ASME B31.3`) is a label we do not control and never revise, but a project
 * spec is a live document in the register. If the spec is reissued at Rev. C,
 * a text field would leave the ITP silently citing Rev. B with no way to
 * detect it. The FK follows the register forward by default; `pinned_revision`
 * is the deliberate opt-out for a stage agreed against a named revision.
 */
final class ItpStageDocument extends Model
{
    use HasUuid;

    /** Project technical specification or datasheet. */
    public const SPEC = 'SPEC';

    /** Isometric, GA or P&ID cited by the activity. */
    public const DRAWING = 'DRAWING';

    /** Method statement, WPS, NDE procedure. */
    public const PROCEDURE = 'PROCEDURE';

    /** The inspection report form to be filled in and signed. */
    public const FORM = 'FORM';

    /** @var list<string> */
    public const USAGES = [self::SPEC, self::DRAWING, self::PROCEDURE, self::FORM];

    protected $fillable = [
        'project_id', 'company_id', 'itp_stage_id', 'document_id',
        'usage', 'pinned_revision_id', 'locator',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ItpStage::class, 'itp_stage_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function pinnedRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'pinned_revision_id');
    }

    public static function usageIsValid(string $usage): bool
    {
        return in_array($usage, self::USAGES, true);
    }

    /**
     * The revision this citation actually resolves to: the pinned one if the
     * stage was agreed against a named revision, otherwise whatever the
     * register currently says is current.
     */
    public function effectiveRevisionId(): ?string
    {
        return $this->pinned_revision_id
            ?? $this->document?->current_revision_id;
    }

    /** @return array{fa: string, en: string} */
    public function usageLabel(): array
    {
        return match ($this->usage) {
            self::SPEC => ['fa' => 'مشخصات فنی', 'en' => 'Specification'],
            self::DRAWING => ['fa' => 'نقشه', 'en' => 'Drawing'],
            self::PROCEDURE => ['fa' => 'دستورالعمل', 'en' => 'Procedure'],
            self::FORM => ['fa' => 'فرم گزارش', 'en' => 'Report form'],
            default => ['fa' => $this->usage, 'en' => $this->usage],
        };
    }
}
