<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\PipingClassValveSpec;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit-size transcription; neither overwrite nor publication. ADR-008 §20. */
final class PmsBulkEntryService
{
    public function __construct(private PmsEntryService $entry, private PipingClassBody $body, private PmsValveEntryService $valves, private ManualValves $manualValves) {}

    public function preview(Project $project, User $actor, string $classId, array $input, array $sizes): array
    {
        return $this->entry->scoped($project, $actor, function () use ($project, $actor, $classId, $input, $sizes): array {
            $input = Arr::only($input, [...array_keys(PmsEntryService::ITEM_TEXT), 'material_key', 'project_definition_id', 'reason', 'note_numbers_text', ...$this->valves->inputKeys()]);
            unset($input['dn'], $input['nps']);
            $input['version'] = 0;
            $sizes = Validator::make(['sizes' => $sizes], [
                'sizes' => ['required', 'array', 'list', 'min:1', 'max:100'],
                'sizes.*' => ['required', 'string', 'max:16', 'distinct'],
            ])->validate()['sizes'];
            $plan = $this->plan($project, $actor, $classId, $input, $sizes);
            $plan['token'] = $plan['conflicts'] ? null : Crypt::encryptString(json_encode([
                'purpose' => 'pms-bulk-create-v1', 'project' => $project->id, 'actor' => $actor->id,
                'company' => $actor->company_id, 'class' => $classId, 'expires' => now()->addMinutes(30)->timestamp,
                'input' => $input, 'sizes' => $sizes, 'fingerprint' => $plan['fingerprint'],
            ], JSON_THROW_ON_ERROR));

            return $plan;
        });
    }

    public function confirm(Project $project, User $actor, string $classId, string $token): int
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            $this->fail('bulk_invalid_preview');
        }
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'pms-bulk-create-v1'
            || ($payload['project'] ?? null) !== $project->id || ($payload['actor'] ?? null) !== $actor->id
            || ($payload['company'] ?? null) !== $actor->company_id || ($payload['class'] ?? null) !== $classId
            || ($payload['expires'] ?? 0) <= now()->timestamp) {
            $this->fail('bulk_invalid_preview');
        }

        return $this->entry->scoped($project, $actor, function () use ($project, $actor, $classId, $payload): int {
            $plan = $this->plan($project, $actor, $classId, $payload['input'], $payload['sizes']);
            if ($plan['conflicts']) {
                $this->fail('bulk_conflicts');
            }
            if (! hash_equals($payload['fingerprint'], $plan['fingerprint'])) {
                $this->fail('bulk_stale');
            }
            foreach ($payload['sizes'] as $size) {
                $this->entry->saveItem($project, $actor, $classId, $payload['input'] + ['size_key' => $size]);
            }

            return count($payload['sizes']);
        }, true);
    }

    /** Must run in the caller's tenant transaction; confirmation also holds the entry locks. */
    private function plan(Project $project, User $actor, string $classId, array $input, array $sizes): array
    {
        $class = PipingClass::where('project_id', $project->id)->whereKey($classId)->firstOrFail();
        abort_unless($this->entry->canWrite($project, $actor, $class), 403);
        $notes = $class->notes()->orderBy('number')->get();
        $definitions = empty($input['project_definition_id']) ? [] : $this->entry->definitions($project, $actor);
        $definition = $definitions[$input['project_definition_id'] ?? ''] ?? null;
        if (! empty($input['project_definition_id']) && ($definition['state']['state'] ?? null) !== 'APPROVED') {
            $this->fail('definition_unavailable');
        }
        $rows = [];
        $basis = [];
        $axes = [];
        $conflicts = 0;
        foreach ($sizes as $size) {
            $prepared = $this->entry->prepareItem($input + ['size_key' => $size]);
            $v = $prepared['v'];
            $data = Arr::only($v, [...array_keys(PmsEntryService::ITEM_TEXT), 'dn', 'note_numbers', 'project_definition_id']);
            $item = new PipingClassItem;
            $item->forceFill($data);
            $item->setRelation('pipingClass', $class);
            $valveSpec = $prepared['valve']['data'] ? new PipingClassValveSpec($prepared['valve']['data']) : null;
            $existing = PipingClassItem::where('project_id', $project->id)->where('piping_class_id', $classId)
                ->where('component_type', $v['component_type'])->where('qualifier', $v['qualifier'] ?? null)
                ->where('dn', $v['dn'])->first();
            $axis = json_encode([$v['component_type'], $v['qualifier'] ?? null, $v['dn']], JSON_THROW_ON_ERROR);
            $conflict = $existing !== null || isset($axes[$axis]);
            $axes[$axis] = true;
            $conflicts += (int) $conflict;
            $rows[] = ['data' => $data, 'conflict' => $conflict, 'existing_id' => $existing?->id,
                'valve_spec' => $prepared['valve']['data'],
                'findings' => array_merge($this->body->findingsForItem($item, $notes->pluck('number')->all()), $this->manualValves->findingsFor($item, $valveSpec)),
                'links' => $this->entry->linkedFindings($item, $definitions)];
            $basis[] = [$v, $prepared['valve'], $prepared['library'], $prepared['size']?->toArray(), $prepared['catalog']['provenance']];
        }
        $fingerprint = hash('sha256', json_encode([
            $class->toArray(), $notes->toArray(), $basis, $rows, $definition ? $definition['row']->toArray() : null,
            $definition['state']['run']->id ?? null,
        ], JSON_THROW_ON_ERROR));

        return compact('class', 'rows', 'conflicts', 'fingerprint') + ['input' => $input];
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['bulk' => __('pms.'.$key)]);
    }
}
