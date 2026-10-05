<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingLine;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\LineReleaseState;
use App\Support\Units\UnitRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Coordinates hydrotest/test-pack grouping for piping lines. */
final class PipingTestPackService
{
    public function __construct(
        private readonly ProjectArchiveService $archives,
        private readonly UnitRegistry $units,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(Project $project, User $actor, array $data): PipingTestPack
    {
        $this->archives->assertWritable($project);

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Test pack code is required.']);
        }

        if (($data['test_pressure'] ?? null) !== null) {
            $unit = (string) ($data['test_pressure_unit'] ?? '');
            if ($unit === '') {
                throw ValidationException::withMessages(['test_pressure_unit' => 'Test pressure unit is required.']);
            }
            if (! $this->units->knows($unit)) {
                throw ValidationException::withMessages(['test_pressure_unit' => 'Unknown unit: '.$unit]);
            }
        }

        $subsystemId = $data['subsystem_id'] ?? null;
        if ($subsystemId !== null && $subsystemId !== '') {
            $subsystem = Subsystem::query()->whereKey((string) $subsystemId)->firstOrFail();
            if ($subsystem->project_id !== $project->id) {
                throw ValidationException::withMessages(['subsystem_id' => 'Subsystem does not belong to this project.']);
            }
        } else {
            $subsystemId = null;
        }

        return PipingTestPack::create([
            'project_id' => $project->id,
            'company_id' => $actor->company_id,
            'subsystem_id' => $subsystemId,
            'code' => $code,
            'title_en' => $data['title_en'] ?? null,
            'title_fa' => $data['title_fa'] ?? null,
            'state' => PipingTestPack::DRAFT,
            'test_pressure' => $data['test_pressure'] ?? null,
            'test_pressure_unit' => $data['test_pressure_unit'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function assignLine(PipingLine $line, User $actor, ?PipingTestPack $pack): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        if ($pack !== null && $pack->project_id !== $line->project_id) {
            throw ValidationException::withMessages(['test_pack_id' => 'Test pack does not belong to this project.']);
        }

        if ($pack !== null && $pack->state !== PipingTestPack::DRAFT) {
            throw ValidationException::withMessages(['test_pack_id' => 'Only draft test packs can be changed.']);
        }

        $line->forceFill(['test_pack_id' => $pack?->id])->save();

        return $line->refresh();
    }

    public function transition(PipingTestPack $pack, User $actor, string $to): PipingTestPack
    {
        $project = Project::query()->findOrFail($pack->project_id);
        $this->archives->assertWritable($project);

        if (! in_array($to, PipingTestPack::nextStates((string) $pack->state), true)) {
            throw ValidationException::withMessages(['state' => 'Invalid test-pack transition.']);
        }

        return DB::transaction(function () use ($pack, $actor, $to): PipingTestPack {
            $locked = PipingTestPack::query()->whereKey($pack->id)->lockForUpdate()->firstOrFail();

            if ($to === PipingTestPack::READY) {
                $lines = PipingLine::query()->where('test_pack_id', $locked->id)->get();
                if ($lines->isEmpty()) {
                    throw ValidationException::withMessages(['state' => 'A test pack needs at least one line before it can be marked ready.']);
                }

                $notReleased = $lines
                    ->reject(fn (PipingLine $line): bool => $line->release_state === LineReleaseState::RELEASED)
                    ->pluck('line_number')
                    ->all();

                if ($notReleased !== []) {
                    throw ValidationException::withMessages([
                        'state' => 'These lines are not released for construction: '.implode(', ', $notReleased),
                    ]);
                }

                $locked->forceFill(['state' => $to, 'ready_at' => now(), 'ready_by' => $actor->id])->save();
            } elseif ($to === PipingTestPack::TESTED) {
                $locked->forceFill(['state' => $to, 'tested_at' => now(), 'tested_by' => $actor->id])->save();
            } else {
                $locked->forceFill(['state' => $to, 'accepted_at' => now(), 'accepted_by' => $actor->id])->save();
            }

            return $locked->refresh();
        });
    }
}
