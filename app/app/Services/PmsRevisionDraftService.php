<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Project;
use App\Models\User;
use App\Support\CdeState;
use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\MaterialReference;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Narrow, nominated-author edits of the next revision's working copy. */
final class PmsRevisionDraftService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PmsAccessService $authors,
        private readonly ProjectArchiveService $archives,
        private readonly PmsRevisionDraftValidator $validator,
        private readonly PmsApprovedRevisionBaseline $baseline,
        private readonly MaterialReference $materials,
    ) {}

    /**
     * Change an existing PIPE_SCHEDULE rule, not its identity, range or kind.
     * This edits ONLY the WIP revision's copy; the live published class and
     * previous approved snapshots remain untouched. No publication yet.
     *
     * @return array<string, mixed> Updated working-copy specification.
     */
    public function changePipeSchedule(
        Project $project,
        User $actor,
        string $documentId,
        string $classId,
        string $ruleId,
        string $schedule,
    ): array {
        $schedule = trim($schedule);
        if ($schedule === '' || mb_strlen($schedule) > 32) {
            throw ValidationException::withMessages(['schedule' => 'Enter a pipe schedule (1–32 characters).']);
        }

        return $this->mutateDraft($project, $actor, $documentId, $classId,
            function (array &$spec) use ($ruleId, $schedule): void {
                $found = false;
                foreach ($spec['rules'] as &$rule) {
                    if (($rule['id'] ?? null) !== $ruleId) {
                        continue;
                    }
                    if ($found || ($rule['rule_kind'] ?? null) !== 'PIPE_SCHEDULE') {
                        throw ValidationException::withMessages(['rule_id' => 'Choose one existing PIPE_SCHEDULE rule.']);
                    }
                    if (! is_array($rule['value'] ?? null) || ! is_string($rule['value']['schedule'] ?? null)) {
                        throw ValidationException::withMessages(['rule_id' => 'The schedule rule has no schedule value.']);
                    }
                    $rule['value']['schedule'] = $schedule;
                    $found = true;
                }
                unset($rule);

                if (! $found) {
                    throw ValidationException::withMessages(['rule_id' => 'Choose one existing PIPE_SCHEDULE rule.']);
                }
            });
    }

    /**
     * Set an explicit B31.3 material on an existing PAD branch rule, or pass
     * null to remove the override and inherit the header PIPE_MATERIAL at each
     * applicable DN. The issued class and all prior snapshots stay immutable.
     *
     * @param  array<string, mixed>|null  $padMaterial  spec/grade/optional PSL and optional S override identity
     * @return array<string, mixed> Updated working-copy specification.
     */
    public function changePadMaterial(
        Project $project,
        User $actor,
        string $documentId,
        string $classId,
        string $branchRuleId,
        ?array $padMaterial,
    ): array {
        $selected = $padMaterial === null ? null : $this->canonicalPadMaterial($padMaterial);

        return $this->mutateDraft($project, $actor, $documentId, $classId,
            function (array &$spec) use ($branchRuleId, $selected): void {
                $found = false;
                foreach ($spec['branch_rules'] as &$rule) {
                    if (($rule['id'] ?? null) !== $branchRuleId) {
                        continue;
                    }
                    if ($found || ($rule['reinforcement'] ?? null) !== 'PAD') {
                        throw ValidationException::withMessages(['branch_rule_id' => 'Choose one existing branch rule with reinforcement PAD.']);
                    }

                    $branchValue = $rule['value'] ?? null;
                    if ($branchValue !== null && ! is_array($branchValue)) {
                        throw ValidationException::withMessages(['branch_rule_id' => 'The PAD branch-rule value must be a JSON object when present.']);
                    }
                    $branchValue ??= [];

                    if ($selected === null) {
                        // Preserve the representation when the rule already
                        // inherits the header material; otherwise remove only
                        // the explicit PAD-material leaf.
                        if (isset($branchValue['pad_material'])) {
                            unset($branchValue['pad_material']);
                            $rule['value'] = $branchValue === [] ? null : $branchValue;
                        }
                    } else {
                        $existing = is_array($branchValue['pad_material'] ?? null)
                            ? $branchValue['pad_material']
                            : [];
                        // Keep any forward-compatible, non-editable material
                        // metadata while replacing the fields this screen owns.
                        $rule['value'] = [
                            ...$branchValue,
                            'pad_material' => array_replace($existing, $selected),
                        ];
                    }
                    $found = true;
                }
                unset($rule);

                if (! $found) {
                    throw ValidationException::withMessages(['branch_rule_id' => 'Choose one existing branch rule with reinforcement PAD.']);
                }
            });
    }

    /**
     * Read the current revision's PAD working copies for the screen. Draft
     * rows remain subject to the existing tenant/project RLS policy; only the
     * originating company can see its own working-copy rows.
     *
     * @return array{document: Document, revision: ?DocumentRevision, pads: list<array<string, mixed>>}
     */
    public function padMaterialWorkspace(Project $project, User $actor, string $documentId): array
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $documentId): array {
            $document = Document::with('currentRevision')
                ->where('project_id', $project->id)
                ->whereKey($documentId)
                ->firstOrFail();
            $this->assertPmsDocument($document);
            $revision = $document->currentRevision;

            if ($revision === null) {
                return ['document' => $document, 'revision' => null, 'pads' => []];
            }

            $drafts = DB::table('pms_class_revision_drafts')
                ->where('project_id', $project->id)
                ->where('pms_document_id', $document->id)
                ->where('document_revision_id', $revision->id)
                ->orderBy('piping_class_id')
                ->get();

            $baseline = $this->baseline->before($document, $revision);
            $canEdit = $document->company_id === $actor->company_id
                && $revision->cde_state === CdeState::WIP
                && $this->archives->isWritable($project)
                && $this->authors->allows($project, $actor);
            $pads = [];

            foreach ($drafts as $draft) {
                $spec = $this->decodeSpecification($draft->specification, $draft->piping_class_id);
                $class = $spec['class'];
                foreach ($spec['branch_rules'] as $rule) {
                    if (! is_array($rule)) {
                        throw ValidationException::withMessages(['specification' => 'Invalid PMS branch-rule structure.']);
                    }
                    if (($rule['reinforcement'] ?? null) !== 'PAD') {
                        continue;
                    }

                    $value = $rule['value'] ?? null;
                    if ($value !== null && ! is_array($value)) {
                        throw ValidationException::withMessages(['specification' => 'The PAD branch-rule value must be an object when present.']);
                    }
                    $padMaterial = is_array($value) ? ($value['pad_material'] ?? null) : null;
                    if ($padMaterial !== null && ! is_array($padMaterial)) {
                        throw ValidationException::withMessages(['specification' => 'The PAD material must be an object with a specification and grade.']);
                    }

                    $pads[] = [
                        'class_id' => $draft->piping_class_id,
                        'class_code' => (string) ($class['code'] ?? $draft->piping_class_id),
                        'class_name_en' => (string) ($class['name_en'] ?? ''),
                        'class_name_fa' => (string) ($class['name_fa'] ?? ''),
                        'branch_rule_id' => (string) ($rule['id'] ?? ''),
                        'header_range' => (string) ($rule['header_range'] ?? ''),
                        'branch_range' => (string) ($rule['branch_range'] ?? ''),
                        'component_type' => (string) ($rule['component_type'] ?? ''),
                        'p2p_kind' => $rule['p2p_kind'] ?? null,
                        'pad_thickness' => $rule['pad_thickness'] ?? null,
                        'pad_material' => $padMaterial,
                        'header_materials' => $this->headerMaterials($spec, (string) ($rule['header_range'] ?? '')),
                        'can_edit' => $canEdit && $baseline->has($draft->piping_class_id),
                    ];
                }
            }

            return ['document' => $document, 'revision' => $revision, 'pads' => $pads];
        }, $project->id);
    }

    /** Serialize policy changes and all draft edits under the project's PMS lock. */
    private function mutateDraft(Project $project, User $actor, string $documentId, string $classId, callable $mutate): array
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $documentId, $classId, $mutate): array {
            $this->archives->assertWritable($project);

            // Serialize policy changes with edits; never trust a nomination
            // checked before this transaction or before the latest suspension.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pms-entry:'.$project->id]);
            if (! $this->authors->allows($project, $actor)) {
                throw ValidationException::withMessages(['pms_access' => 'An active PMS author nomination is required.']);
            }

            $document = Document::whereKey($documentId)
                ->where('project_id', $project->id)
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()->firstOrFail();
            $this->assertPmsDocument($document);

            $revision = DocumentRevision::whereKey($document->current_revision_id)
                ->where('document_id', $document->id)
                ->lockForUpdate()->firstOrFail();
            if ($revision->cde_state !== CdeState::WIP) {
                throw ValidationException::withMessages(['revision' => 'PMS working copies can only be edited in WIP.']);
            }

            $draft = DB::table('pms_class_revision_drafts')
                ->where('project_id', $project->id)
                ->where('company_id', $actor->company_id)
                ->where('pms_document_id', $document->id)
                ->where('document_revision_id', $revision->id)
                ->where('piping_class_id', $classId)
                ->lockForUpdate()->first();
            if ($draft === null) {
                throw ValidationException::withMessages(['piping_class_id' => 'No WIP working copy exists for this PMS class.']);
            }

            $spec = $this->decodeSpecification($draft->specification, $classId);
            $mutate($spec);

            // Compare against the previous approved issue, not the mutable
            // live class. The guard allows only schedule and PAD-material leaves.
            $baseline = $this->baseline->before($document, $revision)->get($classId);
            if ($baseline === null) {
                throw ValidationException::withMessages(['specification' => 'A prior approved PMS snapshot is required.']);
            }
            $this->validator->assertAllowedDelta(
                json_decode($baseline->specification, true, 512, JSON_THROW_ON_ERROR), $spec,
            );

            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => json_encode($spec, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $spec;
        }, $project->id);
    }

    /** @return array<string, mixed> */
    private function canonicalPadMaterial(array $input): array
    {
        $allowed = ['spec', 'grade', 'psl', 's_mpa_override', 'p_no', 'group_no'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['pad_material' => 'Unsupported PAD material fields were supplied.']);
        }

        $spec = is_string($input['spec'] ?? null) ? trim($input['spec']) : '';
        $grade = is_string($input['grade'] ?? null) ? trim($input['grade']) : '';
        $pslInput = $input['psl'] ?? null;
        if ($pslInput !== null && $pslInput !== '' && ! is_string($pslInput)) {
            throw ValidationException::withMessages(['pad_material' => 'PSL must be text when supplied.']);
        }
        $psl = is_string($pslInput) && trim($pslInput) !== '' ? trim($pslInput) : null;

        if ($spec === '' || $grade === '' || mb_strlen($spec) > 80 || mb_strlen($grade) > 80
            || ($psl !== null && mb_strlen($psl) > 16)) {
            throw ValidationException::withMessages(['pad_material' => 'Enter a material specification and grade; PSL is optional.']);
        }

        $stressInput = $input['s_mpa_override'] ?? null;
        $stressInput = $stressInput === '' ? null : $stressInput;
        $pNoInput = $input['p_no'] ?? null;
        if ($pNoInput !== null && $pNoInput !== '' && ! is_string($pNoInput)) {
            throw ValidationException::withMessages(['pad_material' => 'P-No must be text when supplied.']);
        }
        $pNo = is_string($pNoInput) && trim($pNoInput) !== '' ? trim($pNoInput) : null;
        $groupInput = $input['group_no'] ?? null;
        $groupInput = $groupInput === '' ? null : $groupInput;

        if ($pNo !== null && (mb_strlen($pNo) > 8
            || preg_match('/^[1-9]\d*(?:[A-Z]{1,2})?$/', $pNo) !== 1)) {
            throw ValidationException::withMessages(['pad_material' => 'P-No must match the printed Section IX format, such as 1, 5A, or 15E.']);
        }
        if ($groupInput !== null && ! $this->validGroupNumber($groupInput)) {
            throw ValidationException::withMessages(['pad_material' => 'Group No must be a positive integer no greater than 10000.']);
        }

        if ($stressInput !== null) {
            if (! is_numeric($stressInput) || ! is_finite((float) $stressInput)
                || (float) $stressInput <= 0.0 || (float) $stressInput >= 100_000_000
                || $pNo === null || $groupInput === null) {
                throw ValidationException::withMessages([
                    'pad_material' => 'A manual positive S override requires a valid Section IX P-No and positive integer Group No.',
                ]);
            }

            return [
                'spec' => mb_strtoupper($spec),
                'grade' => mb_strtoupper($grade),
                'psl' => $psl,
                's_mpa_override' => (float) $stressInput,
                'p_no' => $pNo,
                'group_no' => (int) $groupInput,
            ];
        }

        try {
            $material = $this->materials->find($spec, $grade, $psl);
        } catch (UnknownMaterialException $exception) {
            throw ValidationException::withMessages([
                'pad_material' => 'PAD material was not found in the B31.3-2024 Table A-1 reference: '.$exception->getMessage(),
            ]);
        }

        return [
            'spec' => $material->spec,
            'grade' => $material->typeGrade,
            'psl' => $material->psl,
            's_mpa_override' => null,
            'p_no' => $pNo,
            'group_no' => $groupInput === null ? null : (int) $groupInput,
        ];
    }

    private function validGroupNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 10_000;
        }

        return is_string($value)
            && preg_match('/^[1-9]\d{0,4}$/', $value) === 1
            && (int) $value <= 10_000;
    }

    /** @return array<string, mixed> */
    private function decodeSpecification(string $json, string $classId): array
    {
        $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($spec) || ! is_array($spec['class'] ?? null)
            || ($spec['class']['id'] ?? null) !== $classId
            || ! is_array($spec['rules'] ?? null) || ! array_is_list($spec['rules'])
            || ! is_array($spec['branch_rules'] ?? null) || ! array_is_list($spec['branch_rules'])) {
            throw ValidationException::withMessages(['specification' => 'Invalid PMS working-copy structure.']);
        }

        return $spec;
    }

    private function assertPmsDocument(Document $document): void
    {
        if ($document->doc_type !== 'PMS' || $document->discipline !== 'PIPING') {
            throw ValidationException::withMessages(['pms_document_id' => 'A PIPING/PMS document is required.']);
        }
    }

    /** @param array<string, mixed> $specification @return list<array{range:string,label:string}> */
    private function headerMaterials(array $specification, string $headerRange): array
    {
        if (preg_match('/^\[(\d+),(\d+)\)$/', $headerRange, $header) !== 1) {
            return [];
        }
        $headerFrom = (int) $header[1];
        $headerTo = (int) $header[2];
        $materials = [];

        foreach ($specification['rules'] as $rule) {
            if (! is_array($rule) || ($rule['rule_kind'] ?? null) !== 'PIPE_MATERIAL'
                || preg_match('/^\[(\d+),(\d+)\)$/', (string) ($rule['dn_range'] ?? ''), $range) !== 1) {
                continue;
            }
            $from = max($headerFrom, (int) $range[1]);
            $to = min($headerTo, (int) $range[2]);
            if ($from >= $to || ! is_array($rule['value'] ?? null)) {
                continue;
            }
            $value = $rule['value'];
            $label = trim((string) ($value['spec'] ?? '').' '.(string) ($value['grade'] ?? ''));
            if (is_string($value['psl'] ?? null) && trim($value['psl']) !== '') {
                $label .= ' '.trim($value['psl']);
            }
            if (isset($value['s_mpa_override']) && is_numeric($value['s_mpa_override'])) {
                $label .= ' · S='.((string) $value['s_mpa_override']).' MPa';
            }
            $materials[] = ['range' => "[{$from},{$to})", 'label' => $label];
        }

        return $materials;
    }
}
