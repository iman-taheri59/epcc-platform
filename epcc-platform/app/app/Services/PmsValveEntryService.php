<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingClassItem;
use App\Models\PipingClassValveSpec;
use App\Support\Reference\MaterialLibrary;
use App\Support\Reference\ValveConstruction;
use Illuminate\Validation\ValidationException;

/** Faithful valve-layer transcription; engineering findings never authorize use. */
final class PmsValveEntryService
{
    public const TEXT = ['construction_standard' => 32, 'trim_standard' => 16, 'trim_code' => 16, 'operator' => 24, 'description_as_written' => 10000];

    public function __construct(private MaterialLibrary $materials, private ValveConstruction $construction) {}

    public function inputKeys(): array
    {
        return ['valve_mode', 'valve_trim_key', ...array_map(fn (string $key): string => 'valve_'.$key, array_keys(self::TEXT))];
    }

    public function catalog(): array
    {
        $trims = [];
        foreach ($this->materials->trims() as $trim) {
            $trims[hash('sha256', $trim['standard'].'|'.$trim['code'])] = $trim;
        }

        return ['trims' => $trims, 'construction' => $this->construction->all(), 'operators' => PipingClassValveSpec::OPERATORS];
    }

    public function prepare(array $input): array
    {
        $mode = $input['valve_mode'] ?? 'keep';
        if (! array_key_exists('valve_mode', $input) && array_intersect(array_keys($input), $this->inputKeys()) !== []) {
            $this->fail('valve_mode');
        }
        if (! is_string($mode) || ! in_array($mode, ['keep', 'replace', 'clear'], true) || (array_key_exists('valve_mode', $input) && $input['valve_mode'] === null)) {
            $this->fail('valve_mode');
        }
        if ($mode !== 'replace') {
            return ['mode' => $mode, 'data' => null, 'selection' => null];
        }
        if (($input['component_type'] ?? null) !== 'VALVE') {
            $this->fail('valve_mode');
        }
        $data = [];
        foreach (self::TEXT as $field => $max) {
            $key = 'valve_'.$field;
            $value = $input[$key] ?? null;
            if ($value !== null && (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{009F}]/u', $value))) {
                $this->fail($key);
            }
            $data[$field] = $value !== null && preg_match('/^[\s\p{Z}]*$/u', $value) !== 1 ? $value : null;
        }
        $key = $input['valve_trim_key'] ?? null;
        $selection = null;
        if ($key !== null && $key !== '') {
            if (! is_string($key) || ! isset($this->catalog()['trims'][$key])) {
                $this->fail('valve_trim_key');
            }
            $trim = $this->catalog()['trims'][$key];
            $data['trim_standard'] = $trim['standard'];
            $data['trim_code'] = $trim['code'];
            $selection = ['trim' => $trim, 'source' => $this->materials->provenance()];
        }
        if (($data['trim_standard'] === null) !== ($data['trim_code'] === null)) {
            $this->fail('valve_trim_code');
        }
        if ($data['operator'] !== null && ! in_array($data['operator'], PipingClassValveSpec::OPERATORS, true)) {
            $this->fail('valve_operator');
        }
        if (count(array_filter($data, fn ($v): bool => $v !== null)) === 0) {
            $this->fail('valve_mode');
        }

        return ['mode' => $mode, 'data' => $data, 'selection' => $selection,
            'references' => ['construction' => $this->construction->provenance(), 'materials' => $this->materials->provenance()]];
    }

    public function apply(PipingClassItem $item, array $prepared): void
    {
        $spec = $item->valveSpec;
        if ($spec) {
            abort_unless($spec->project_id === $item->project_id && $spec->company_id === $item->company_id, 403);
        }
        if ($prepared['mode'] === 'keep') {
            if ($spec && $item->component_type !== 'VALVE') {
                $this->fail('valve_mode');
            }

            return;
        }
        if ($prepared['mode'] === 'clear') {
            $spec?->delete();
        } else {
            $spec ??= new PipingClassValveSpec;
            $spec->fill($prepared['data']);
            $spec->project_id = $item->project_id;
            $spec->company_id = $item->company_id;
            $spec->piping_class_item_id = $item->id;
            $spec->save();
        }
        $provenance = $item->entry_provenance ?? [];
        $provenance['valve_selection'] = $prepared['selection'];
        $provenance['valve_references'] = $prepared['references'] ?? null;
        $item->entry_provenance = $provenance;
        $item->save();
        $item->unsetRelation('valveSpec');
    }

    private function fail(string $field): never
    {
        throw ValidationException::withMessages(['item.'.$field => __('pms.valve_invalid')]);
    }
}
