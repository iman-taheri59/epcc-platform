<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\LineDocument;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\ProjectPipingConfig;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\CdeState;
use App\Support\LineReleaseState;
use App\Support\Numbering\NumberEngine;
use App\Support\Numbering\NumberPattern;
use App\Support\Numbering\ParsedNumber;
use App\Support\Reference\PipeReference;
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

        return ProjectPipingConfig::create([
            'project_id' => $project->id,
            'company_id' => $project->owner_company_id,
            'line_number_pattern' => ProjectPipingConfig::defaultPattern(),
            'services' => [],
            'coatings' => [],
            'insulation_types' => [],
        ]);
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
