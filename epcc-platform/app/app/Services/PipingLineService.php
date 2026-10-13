<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\Document;
use App\Models\LineDocument;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\ProjectPipingConfig;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\CdeState;
use App\Support\TenantContext;
use App\Support\LineReleaseState;
use App\Support\Numbering\NumberEngine;
use App\Support\Numbering\NumberPattern;
use App\Support\Numbering\ParsedNumber;
use App\Support\Reference\PipeReference;
use App\Support\Units\Exceptions\IncompatibleUnitException;
use App\Support\Units\Exceptions\UnknownUnitException;
use App\Support\Units\UnitRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The piping line register and the AFC gate (ADR-006 phase 1).
 *
 * Two jobs that look separate and are not:
 *
 *   1. Give a line a number - composed from the project's pattern, or accepted
 *      as supplied when it arrives from a P&ID or a 3D model.
 *   2. Decide when the field may build it.
 *
 * They meet at validation. A number is only meaningful if every part of it is
 * something the project has registered, and the AFC gate is only meaningful if
 * the evidence behind it is real. Both refusals happen here, loudly, in the
 * user's language - the same division of labour as ProjectArchiveService,
 * where the database is a backstop and the service is what a human talks to.
 */
final class PipingLineService
{
    public function __construct(
        private readonly NumberEngine $engine,
        private readonly PipeReference $reference,
        private readonly ProjectArchiveService $archives,
        private readonly UnitRegistry $units,
        private readonly TenantContext $tenant,
    ) {}

    // ------------------------------------------------------------ create --

    /**
     * Create a line, allocating the next sequence from the project pattern.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Subsystem $subsystem, User $actor, array $attributes = []): PipingLine
    {
        $project = Project::query()->findOrFail($subsystem->project_id);
        $this->archives->assertWritable($project);

        /*
         * WITHDRAWN (the user's ruling, 2026-09-18): the system no longer
         * invents numbers.
         *
         * The counter reads max(sequence) over rows that still EXIST, so
         * deleting a line during design hands its number straight back to the
         * next one - and a number that has reached an isometric, a test pack or
         * a purchase order must never be reused. The fix is not a cleverer
         * counter but a different division of labour: the engineering tag list
         * issues numbers, this system checks them.
         *
         * Kept as a guarded path rather than deleted because the numbering
         * ENGINE it exercises (compose, vocabularies, width) is still how
         * `preview()` shows an engineer what a conforming number looks like.
         */
        if (! $this->autoNumberingEnabled($project)) {
            throw ValidationException::withMessages([
                'line_number' => __('epcc.line.auto_numbering_withdrawn'),
            ]);
        }

        return DB::transaction(function () use ($subsystem, $actor, $attributes, $project): PipingLine {
            $config = $this->configFor($project);
            $pattern = $config->pattern();

            // Lock the SUBSYSTEM row, because the counter is scoped to it.
            // Same pattern as MCC/punch numbering; MR/PO lock `projects`
            // because their counters are project-wide. Locking the wrong row
            // is how two concurrent creates get the same number.
            Subsystem::query()->whereKey($subsystem->id)->lockForUpdate()->first();

            $seq = $this->nextSequence($subsystem);

            $values = $this->tokenValues($subsystem, $attributes, $seq);
            $vocab = $this->vocabularies($project, $subsystem);

            $number = $this->engine->compose($pattern, $values, $vocab);

            $this->assertNumberIsFree($project, $number);

            return PipingLine::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'subsystem_id' => $subsystem->id,
                'line_number' => $number,
                'number_tokens' => $values,
                'sequence' => $seq,
                'nominal_size' => $attributes['nominal_size'] ?? null,
                'service_code' => $attributes['service_code'] ?? null,
                'piping_class_id' => $attributes['piping_class_id'] ?? null,
                'coating_code' => $attributes['coating_code'] ?? null,
                'release_state' => LineReleaseState::DRAFT,
            ] + $this->designAttributes($attributes));
        });
    }

    /**
     * Register a line that already has a number (import, or manual entry of an
     * existing tag).
     *
     * ADR-006 §10.2 is explicit that import must not invent numbers, so this
     * path never allocates a sequence. The supplied number is parsed and
     * checked, and - this is the important part - a number that does not fit
     * the project pattern is still STORED, with whatever could be read from it
     * recorded in number_tokens.
     *
     * Refusing to store it would be the wrong trade. The line exists in the
     * real world whatever our pattern says; an EPCC project always has a
     * handful of legacy or vendor-package tags that predate the scheme. What
     * matters is that they are visible AS non-conforming rather than silently
     * normalised or dropped.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(Subsystem $subsystem, User $actor, string $lineNumber, array $attributes = []): PipingLine
    {
        $project = Project::query()->findOrFail($subsystem->project_id);
        $this->archives->assertWritable($project);

        $lineNumber = trim($lineNumber);
        if ($lineNumber === '') {
            throw ValidationException::withMessages([
                'line_number' => __('epcc.line.number_required'),
            ]);
        }

        return DB::transaction(function () use ($subsystem, $actor, $lineNumber, $attributes, $project): PipingLine {
            $this->assertNumberIsFree($project, $lineNumber);

            $parsed = $this->parse($project, $subsystem, $lineNumber);

            return PipingLine::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'subsystem_id' => $subsystem->id,
                'line_number' => $lineNumber,
                'number_tokens' => $parsed->matched ? $parsed->tokens : null,
                // Deliberately NULL: we did not allocate this number, and
                // writing the parsed sequence here would let the next
                // allocation collide with an imported one.
                'sequence' => null,
                'nominal_size' => $attributes['nominal_size'] ?? $parsed->token('size'),
                'service_code' => $attributes['service_code'] ?? $parsed->token('service'),
                'piping_class_id' => $attributes['piping_class_id'] ?? null,
                'coating_code' => $attributes['coating_code'] ?? $parsed->token('coating'),
                'release_state' => LineReleaseState::DRAFT,
            ] + $this->designAttributes($attributes));
        });
    }

    /**
     * Whether this project still allows the withdrawn auto-numbering path.
     *
     * Off unless explicitly enabled. The flag exists so the fixtures and the
     * numbering tests can keep exercising the engine without the production
     * path being open.
     */
    public function autoNumberingEnabled(Project $project): bool
    {
        return (bool) ($this->configFor($project)->allow_auto_line_numbers ?? false);
    }

    // ---------------------------------------------------------- withdraw --

    /**
     * Withdraw a line (the user's ruling, 2026-09-18).
     *
     * The row survives so the line NUMBER stays reserved. That is the whole
     * point: this phase withdrew auto-numbering after finding that `max()`
     * cannot see deleted rows, and a hard DELETE reopens the same hole from the
     * other side - the number goes free and somebody retypes one that is
     * printed on an issued isometric.
     *
     * Two refusals, both about the line being real rather than a typo:
     *
     *   RELEASED  - it has been issued to the field. Stopping construction is
     *               revertRelease() with a reason, which is a visible and
     *               reversible act; deleting would remove a line that may
     *               already be welded.
     *   modelled  - components exist on it. Their joints and dia-inch are
     *               somebody's progress claim. Strip the model first, which
     *               forces the person to see what they are discarding.
     */
    public function withdraw(PipingLine $line, User $actor, string $reason): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        if ($line->release_state === LineReleaseState::DELETED) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.already_deleted', ['number' => $line->line_number]),
            ]);
        }

        if (! LineReleaseState::canTransition($line->release_state, LineReleaseState::DELETED)) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.cannot_delete_released', ['number' => $line->line_number]),
            ]);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'deletion_reason' => __('epcc.line.deletion_reason_required'),
            ]);
        }

        $components = Component::query()->where('piping_line_id', $line->id)->count();

        if ($components > 0) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.still_modelled', [
                    'number' => $line->line_number,
                    'count' => $components,
                ]),
            ]);
        }

        $line->update([
            'release_state' => LineReleaseState::DELETED,
            'deleted_at' => now(),
            'deleted_by' => $actor->id,
            'deletion_reason' => $reason,
        ]);

        return $line->fresh();
    }

    // ------------------------------------------------------------- parse --

    /** Read a supplied number against the project's pattern and registers. */
    public function parse(Project $project, ?Subsystem $subsystem, string $number): ParsedNumber
    {
        $config = $this->configFor($project);

        return $this->engine->parse(
            $config->pattern(),
            $number,
            $this->vocabularies($project, $subsystem),
        );
    }

    /**
     * What the next line number on this subsystem would be, without creating
     * anything. Drives the "preview" on the create form.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function preview(Subsystem $subsystem, array $attributes = []): string
    {
        $project = Project::query()->findOrFail($subsystem->project_id);
        $config = $this->configFor($project);

        return $this->engine->compose(
            $config->pattern(),
            $this->tokenValues($subsystem, $attributes, $this->nextSequence($subsystem)),
            $this->vocabularies($project, $subsystem),
        );
    }

    // --------------------------------------------------------- the gate ---

    /**
     * Confirm AFC: the evidence is attached and approved.
     *
     * THE CHECK THAT MAKES THIS A GATE RATHER THAN A FLAG
     * ---------------------------------------------------
     * It is not enough for documents to be LINKED. They must be at an approved
     * revision in the CDE. A line whose isometric is still "issued for review"
     * is not approved for construction, and a gate that accepts the mere
     * presence of a row would certify exactly that - while looking, on every
     * report, like it had been checked.
     */
    public function confirmAfc(PipingLine $line, User $actor, ?string $note = null): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        $this->assertTransition($line, LineReleaseState::AFC_CONFIRMED);

        $missing = $this->missingEvidence($line);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.afc_missing_evidence', [
                    'kinds' => implode(', ', $missing),
                ]),
            ]);
        }

        $unapproved = $this->unapprovedEvidence($line);

        if ($unapproved !== []) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.afc_evidence_not_approved', [
                    'documents' => implode(', ', $unapproved),
                ]),
            ]);
        }

        $line->forceFill([
            'release_state' => LineReleaseState::AFC_CONFIRMED,
            'afc_confirmed_at' => now(),
            'afc_confirmed_by' => $actor->id,
            'release_note' => $note,
        ])->save();

        return $line->refresh();
    }

    /** Issue the line to the field. */
    public function release(PipingLine $line, User $actor, ?string $note = null): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        $this->assertTransition($line, LineReleaseState::RELEASED);

        $line->forceFill([
            'release_state' => LineReleaseState::RELEASED,
            'released_at' => now(),
            'released_by' => $actor->id,
            'release_note' => $note ?? $line->release_note,
        ])->save();

        return $line->refresh();
    }

    /**
     * Pull a line back to DRAFT - a hold, or a design change.
     *
     * Requires a reason. Going backwards is legitimate and must not need a
     * database administrator, but it invalidates an approval somebody gave,
     * so it leaves a record of why.
     */
    public function revertToDraft(PipingLine $line, User $actor, string $reason): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'release_note' => __('epcc.line.revert_needs_reason'),
            ]);
        }

        $this->assertTransition($line, LineReleaseState::DRAFT);

        $line->forceFill([
            'release_state' => LineReleaseState::DRAFT,
            'afc_confirmed_at' => null,
            'afc_confirmed_by' => null,
            'released_at' => null,
            'released_by' => null,
            'release_note' => $reason,
        ])->save();

        return $line->refresh();
    }

    /**
     * Update a line's design conditions and descriptive fields.
     *
     * Deliberately NOT a general-purpose update: it cannot touch the number,
     * the sequence, the subsystem or the release state. Those have their own
     * guarded paths, and the number in particular is what everything else
     * references.
     *
     * A RELEASED line is not editable. Changing the design pressure of a line
     * that has been issued for construction is a revision, not an edit, and
     * the revert-to-draft path already exists and demands a reason.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDesign(PipingLine $line, User $actor, array $attributes): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        if ($line->release_state === LineReleaseState::RELEASED) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.released_not_editable'),
            ]);
        }

        $changes = $this->designAttributes($attributes);

        if ($changes === []) {
            return $line;
        }

        $line->forceFill($changes)->save();

        return $line->refresh();
    }

    /**
     * Evidence kinds required for AFC that this line does not have at all.
     *
     * @return list<string>
     */
    public function missingEvidence(PipingLine $line): array
    {
        $have = LineDocument::query()
            ->where('piping_line_id', $line->id)
            ->pluck('evidence_kind')
            ->all();

        return array_values(array_diff(LineDocument::requiredForAfc(), $have));
    }

    /**
     * Linked evidence whose document is not at an approved CDE revision.
     *
     * @return list<string>
     */
    public function unapprovedEvidence(PipingLine $line): array
    {
        $rows = LineDocument::query()
            ->where('piping_line_id', $line->id)
            ->whereIn('evidence_kind', LineDocument::requiredForAfc())
            ->with('document.currentRevision')
            ->get();

        $bad = [];

        foreach ($rows as $row) {
            $revision = $row->document?->currentRevision;

            // No revision at all means nothing has been issued yet - which is
            // emphatically not approved. Beyond that, PUBLISHED is necessary
            // but not sufficient: only an A-family suitability code authorises
            // construction (a B code is "approved with comments").
            if ($revision === null || ! CdeState::isApproved((string) $revision->cde_state, $revision->suitability)) {
                $bad[] = (string) ($row->document?->number ?? $row->document_id);
            }
        }

        return $bad;
    }

    /** Attach a CDE document as evidence for this line. */
    /**
     * Remove an evidence link.
     *
     * Needed because attaching is not exclusive: several documents can be
     * linked under the same kind, and unapprovedEvidence() checks ALL of them.
     * Found by driving the screen - a wrongly attached B-coded drawing left
     * the line permanently unable to pass AFC, with no way back except a
     * database edit. The gate must be strict, not unescapable.
     *
     * Only editable lines: detaching evidence from a line that is already
     * AFC-confirmed would erase the justification for a decision that has
     * been made. Revert to draft first, which demands a reason and is logged.
     */
    public function detachEvidence(PipingLine $line, User $actor, LineDocument $link): void
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        if (! LineReleaseState::isEditable((string) $line->release_state)) {
            throw ValidationException::withMessages([
                'evidence' => __('epcc.line.evidence_locked'),
            ]);
        }

        if ((string) $link->piping_line_id !== (string) $line->id) {
            throw ValidationException::withMessages([
                'evidence' => __('epcc.line.evidence_other_line'),
            ]);
        }

        $link->delete();
    }

    public function attachEvidence(PipingLine $line, User $actor, Document $document, string $kind): LineDocument
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        if (! in_array($kind, LineDocument::kinds(), true)) {
            throw ValidationException::withMessages([
                'evidence_kind' => __('epcc.line.unknown_evidence_kind', ['kind' => $kind]),
            ]);
        }

        if ((string) $document->project_id !== (string) $line->project_id) {
            // Cross-project evidence would be invisible to RLS in one
            // direction and nonsense in the other.
            throw ValidationException::withMessages([
                'document_id' => __('epcc.line.evidence_other_project'),
            ]);
        }

        $existing = LineDocument::query()
            ->where('piping_line_id', $line->id)
            ->where('document_id', $document->id)
            ->where('evidence_kind', $kind)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LineDocument::create([
            'project_id' => $line->project_id,
            'company_id' => $actor->company_id,
            'piping_line_id' => $line->id,
            'document_id' => $document->id,
            'evidence_kind' => $kind,
        ]);
    }

    // ---------------------------------------------------------- internals --

    /**
     * The project's piping configuration, created with the default pattern on
     * first use.
     *
     * Lazily rather than in the project-setup wizard: every existing project
     * predates this table, and a register that only works for projects created
     * after today is not a register.
     */
    public function configFor(Project $project): ProjectPipingConfig
    {
        $config = ProjectPipingConfig::query()->where('project_id', $project->id)->first();

        if ($config !== null) {
            return $config;
        }

        $defaults = [
            'project_id' => $project->id,
            'company_id' => $project->owner_company_id,
            'line_number_pattern' => ProjectPipingConfig::defaultPattern(),
            'services' => [],
            'coatings' => [],
            'insulation_types' => [],
            'allow_auto_line_numbers' => false,
            'baseline_tolerance_pct' => 2.0,
        ];
        // A contractor's first GET must not INSERT an employer-owned row.
        // Return an unsaved display fallback; do not impersonate the owner or
        // weaken RLS. Archived projects must likewise remain read-only.
        if ($this->tenant->currentGuc() !== $project->owner_company_id
            || ! $this->archives->isWritable($project)) {
            return new ProjectPipingConfig($defaults);
        }

        return ProjectPipingConfig::create($defaults);
    }

    /** Next counter value for a subsystem. */
    private function nextSequence(Subsystem $subsystem): int
    {
        return 1 + (int) PipingLine::query()
            ->where('subsystem_id', $subsystem->id)
            ->max('sequence');
    }

    /**
     * Values for each token in the pattern.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|int|null>
     */
    private function tokenValues(Subsystem $subsystem, array $attributes, int $sequence): array
    {
        $class = isset($attributes['piping_class_id'])
            ? PipingClass::query()->find($attributes['piping_class_id'])
            : null;

        return [
            'size' => $attributes['nominal_size'] ?? null,
            'service' => $attributes['service_code'] ?? null,
            'class' => $class?->code,
            'subsystem' => $subsystem->code,
            'sequence' => $sequence,
            'coating' => $attributes['coating_code'] ?? null,
        ];
    }

    /**
     * The allowed values for every token, assembled from the two kinds of
     * register ADR-006 §11.5 distinguishes: universal reference data from the
     * ADR-007 packs, and per-project tables.
     *
     * @return array<string, list<string>>
     */
    public function vocabularies(Project $project, ?Subsystem $subsystem = null): array
    {
        $config = $this->configFor($project);

        $sizes = array_map(
            static fn ($size): string => $size->nps,
            $this->reference->sizes(),
        );

        $classes = PipingClass::query()
            ->where('project_id', $project->id)
            ->pluck('code')
            ->all();

        // Every subsystem on the project, not just this line's: parsing a
        // supplied number must recognise a subsystem token that points
        // somewhere else, so it can be reported rather than rejected as
        // malformed.
        $subsystems = Subsystem::query()
            ->where('project_id', $project->id)
            ->pluck('code')
            ->all();

        if ($subsystem !== null && ! in_array($subsystem->code, $subsystems, true)) {
            $subsystems[] = $subsystem->code;
        }

        return [
            'size' => array_values($sizes),
            'service' => array_values(array_map(strval(...), (array) $config->services)),
            'class' => array_values(array_map(strval(...), $classes)),
            'subsystem' => array_values(array_map(strval(...), $subsystems)),
            'coating' => array_values(array_map(strval(...), (array) $config->coatings)),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function designAttributes(array $attributes): array
    {
        $keys = [
            'design_pressure', 'design_pressure_unit',
            'design_temperature', 'design_temperature_unit',
            'operating_pressure', 'operating_pressure_unit',
            'operating_temperature', 'operating_temperature_unit',
            'test_pressure', 'test_pressure_unit', 'test_fluid', 'test_type',
            'insulation_type', 'insulation_thickness_mm', 'tracing_type',
            'from_ref', 'to_ref', 'external_ref',
        ];

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes)) {
                $out[$key] = $attributes[$key];
            }
        }

        return $out + $this->canonicalValues($out);
    }

    /**
     * Derive the canonical (kPa / degrees C) mirror of every value/unit pair.
     *
     * ADR-014: the entered value and unit are the source of truth - they are
     * what the drawing says - and these columns exist so that "every line above
     * 10 barg" is an indexed comparison rather than a join against a unit table
     * with arithmetic in the WHERE clause.
     *
     * Derived on every write, never read back as authority. A value with no
     * unit produces no canonical: guessing the unit is exactly the class of
     * assumption that puts a psi number in a barg column.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, float|null>
     */
    private function canonicalValues(array $values): array
    {
        $pairs = [
            'design_pressure' => ['design_pressure_unit', 'design_pressure_kpa'],
            'operating_pressure' => ['operating_pressure_unit', 'operating_pressure_kpa'],
            'test_pressure' => ['test_pressure_unit', 'test_pressure_kpa'],
            'design_temperature' => ['design_temperature_unit', 'design_temperature_c'],
            'operating_temperature' => ['operating_temperature_unit', 'operating_temperature_c'],
        ];

        $out = [];

        foreach ($pairs as $valueKey => [$unitKey, $canonicalKey]) {
            if (! array_key_exists($valueKey, $values)) {
                continue;
            }

            $value = $values[$valueKey];
            $unit = $values[$unitKey] ?? null;

            if ($value === null || $value === '' || ! is_string($unit) || trim($unit) === '') {
                // Clearing the value must clear the mirror too, or a stale
                // canonical would outlive the number it describes.
                $out[$canonicalKey] = null;

                continue;
            }

            try {
                $measurement = $this->units->measure((float) $value, $unit);
            } catch (UnknownUnitException|IncompatibleUnitException $e) {
                // An unknown unit or an impossible temperature is a user
                // error, not a crash: surface it on the field it came from.
                throw ValidationException::withMessages([$unitKey => $e->getMessage()]);
            }

            $out[$canonicalKey] = $measurement->canonical;
        }

        return $out;
    }

    private function assertNumberIsFree(Project $project, string $number): void
    {
        $taken = PipingLine::query()
            ->where('project_id', $project->id)
            ->where('line_number', $number)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'line_number' => __('epcc.line.number_taken', ['number' => $number]),
            ]);
        }
    }

    private function assertTransition(PipingLine $line, string $to): void
    {
        $from = (string) $line->release_state;

        if (! LineReleaseState::canTransition($from, $to)) {
            throw ValidationException::withMessages([
                'release_state' => __('epcc.line.bad_transition', [
                    'from' => __('epcc.line.state.'.strtolower($from)),
                    'to' => __('epcc.line.state.'.strtolower($to)),
                ]),
            ]);
        }
    }
}
