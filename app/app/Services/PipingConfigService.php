<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PipingLine;
use App\Models\Project;
use App\Models\ProjectPipingConfig;
use App\Models\User;
use App\Support\Numbering\Exceptions\AmbiguousPatternException;
use App\Support\Numbering\Exceptions\InvalidPatternException;
use App\Support\Numbering\NumberPattern;
use App\Support\Numbering\PatternToken;
use App\Support\Numbering\TokenKind;
use App\Support\TenantType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Editing a project's piping configuration: the line-number format and the
 * small registers it draws on (ADR-006 §4, §11.5).
 *
 * Making the format data was the whole point; without an editor it is data
 * only a developer can reach, which is the same as a hardcoded rule with extra
 * steps. This service is the safe door onto it.
 *
 * The rules below are not ceremony. A number format is the one setting where a
 * plausible-looking mistake does not fail at save time - it fails weeks later,
 * on the hundredth line, or worse, does not fail at all and quietly produces
 * numbers that collide with a document register.
 */
final class PipingConfigService
{
    public function __construct(
        private readonly ProjectArchiveService $archives,
    ) {}

    /**
     * Replace a project's number pattern.
     *
     * @param  array{separator?: string, tokens: list<array<string, mixed>>}  $pattern
     */
    public function updatePattern(Project $project, User $actor, array $pattern): ProjectPipingConfig
    {
        $this->archives->assertWritable($project);
        $this->assertMayConfigure($project, $actor);

        // Build it before saving. NumberPattern's constructor is the real
        // authority on what is well-formed (one counter at most, no duplicate
        // token names, a non-empty separator), so we let it speak rather than
        // reimplementing its rules here and letting the two drift apart.
        try {
            $candidate = NumberPattern::fromArray($pattern);
        } catch (InvalidPatternException|AmbiguousPatternException $e) {
            throw ValidationException::withMessages([
                'pattern' => $e->getMessage(),
            ]);
        }

        $this->assertAllocatable($candidate);
        $this->assertNotNarrowingAnExistingCounter($project, $candidate);

        return DB::transaction(function () use ($project, $candidate): ProjectPipingConfig {
            $config = ProjectPipingConfig::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                $config = ProjectPipingConfig::create([
                    'project_id' => $project->id,
                    'company_id' => $project->owner_company_id,
                    'line_number_pattern' => $candidate->toArray(),
                    'services' => [],
                    'coatings' => [],
                    'insulation_types' => [],
                ]);

                return $config;
            }

            $config->update(['line_number_pattern' => $candidate->toArray()]);

            return $config->fresh();
        });
    }

    /**
     * Replace one of the project registers (services, coatings, insulation).
     *
     * @param  list<string>  $values
     */
    public function updateRegister(Project $project, User $actor, string $register, array $values): ProjectPipingConfig
    {
        $this->archives->assertWritable($project);
        $this->assertMayConfigure($project, $actor);

        if (! in_array($register, self::registers(), true)) {
            throw ValidationException::withMessages([
                'register' => __('epcc.line.config.unknown_register', ['name' => $register]),
            ]);
        }

        $clean = [];

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            // A value containing the separator would make every number built
            // from it unparseable - the regex cannot tell the operator's dash
            // from a structural one. Refuse at entry, where it is one bad
            // field, rather than at compose time on every future line.
            if (str_contains($value, $this->separatorFor($project))) {
                throw ValidationException::withMessages([
                    'register' => __('epcc.line.config.value_has_separator', [
                        'value' => $value,
                        'separator' => $this->separatorFor($project),
                    ]),
                ]);
            }

            $clean[$value] = true;
        }

        $clean = array_keys($clean);
        sort($clean);

        $removed = $this->valuesInUseButRemoved($project, $register, $clean);

        if ($removed !== []) {
            // Removing a value that existing lines were numbered with does not
            // renumber them - it just makes their numbers unrecognisable to
            // parse(), which is how a register silently stops matching
            // reality. Deliberately a refusal, not a warning.
            throw ValidationException::withMessages([
                'register' => __('epcc.line.config.value_in_use', [
                    'values' => implode(', ', $removed),
                ]),
            ]);
        }

        return DB::transaction(function () use ($project, $register, $clean): ProjectPipingConfig {
            $config = ProjectPipingConfig::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                // A project that has never had a line still needs to be able
                // to set up its registers - that is the normal order of work.
                $config = ProjectPipingConfig::create([
                    'project_id' => $project->id,
                    'company_id' => $project->owner_company_id,
                    'line_number_pattern' => ProjectPipingConfig::defaultPattern(),
                    'services' => [],
                    'coatings' => [],
                    'insulation_types' => [],
                ]);
            }

            $config->update([$register => $clean]);

            return $config->fresh();
        });
    }

    /** @return list<string> */
    public static function registers(): array
    {
        return ['services', 'coatings', 'insulation_types'];
    }

    // ---------------------------------------------------------- the rules --

    /**
     * A pattern we could never allocate from is not a format, it is a trap.
     *
     * Verified rather than assumed: composing a counter-less pattern twice
     * with the same inputs returns the identical string both times, so the
     * second create dies on the uniqueness check with "number already taken" -
     * a message that describes the symptom and hides the cause. The project is
     * then stuck at one line per subsystem with no hint why.
     *
     * Import-only projects are a real case, but that is `register()`, which
     * never allocates and does not consult this pattern at all.
     */
    private function assertAllocatable(NumberPattern $pattern): void
    {
        if ($pattern->counterToken() === null) {
            throw ValidationException::withMessages([
                'pattern' => __('epcc.line.config.needs_counter'),
            ]);
        }
    }

    /**
     * Do not let the counter width shrink below what the project has already
     * issued.
     *
     * Padding is render-time, so narrowing width from 3 to 2 does not corrupt
     * stored numbers - but line 100 onwards would render as "100" against
     * earlier "001", and a register that sorts as text would interleave them.
     * Worse, it is invisible until the project crosses the threshold.
     */
    private function assertNotNarrowingAnExistingCounter(Project $project, NumberPattern $candidate): void
    {
        $counter = $candidate->counterToken();

        if ($counter === null || $counter->width === 0) {
            return;
        }

        $highest = (int) PipingLine::query()
            ->where('project_id', $project->id)
            ->max('sequence');

        if ($highest === 0) {
            return;
        }

        $needed = strlen((string) $highest);

        if ($counter->width < $needed) {
            throw ValidationException::withMessages([
                'pattern' => __('epcc.line.config.counter_too_narrow', [
                    'width' => $counter->width,
                    'needed' => $needed,
                    'highest' => $highest,
                ]),
            ]);
        }
    }

    /**
     * Only the owner company configures numbering.
     *
     * The format is a project-wide convention that appears on every drawing and
     * every material take-off; a contractor changing it mid-job would renumber
     * nobody's existing work but would make their own new lines inconsistent
     * with everyone else's.
     */
    private function assertMayConfigure(Project $project, User $actor): void
    {
        $isOwner = $actor->company_id === $project->owner_company_id
            && $actor->company?->tenant_type === TenantType::OWN;

        if (! $isOwner) {
            throw ValidationException::withMessages([
                'pattern' => __('epcc.line.config.owner_only'),
            ]);
        }
    }

    /**
     * Values that existing lines were numbered with but which the new register
     * no longer contains.
     *
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private function valuesInUseButRemoved(Project $project, string $register, array $incoming): array
    {
        $column = match ($register) {
            'services' => 'service_code',
            'coatings' => 'coating_code',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        $used = PipingLine::query()
            ->where('project_id', $project->id)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->map(strval(...))
            ->all();

        return array_values(array_diff($used, $incoming));
    }

    private function separatorFor(Project $project): string
    {
        $config = ProjectPipingConfig::query()->where('project_id', $project->id)->first();

        if ($config === null) {
            return '-';
        }

        return $config->pattern()->separator;
    }
}
