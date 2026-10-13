<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPipingConfig;
use App\Models\User;
use App\Support\Units\Exceptions\UnknownUnitException;
use App\Support\Units\Quantity;
use App\Support\Units\UnitRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Which unit a given user sees for a given quantity (ADR-014).
 *
 * Three layers, resolved in order:
 *
 *   user override  ->  project default  ->  pack canonical
 *
 * The user's decision was "project default, user may override". The third
 * layer is not a fourth opinion, it is the floor: a project that has never
 * configured units still has to display something, and the canonical unit is
 * the only answer that is always defined.
 *
 * NULL AND EMPTY MEAN DIFFERENT THINGS on `users.unit_preferences`. Null is
 * "inherit whatever the project says"; an object with a key is "I have chosen
 * this, even if it happens to match". Collapsing them would silently freeze a
 * user's display at whatever the project default was on the day they first
 * opened the screen.
 */
final class UnitPreferenceService
{
    public function __construct(
        private readonly UnitRegistry $units,
        private readonly ProjectArchiveService $archives,
    ) {}

    /**
     * The unit to display a quantity in, for this user on this project.
     */
    public function displayUnit(string $quantity, ?Project $project = null, ?User $user = null): string
    {
        if (! Quantity::isKnown($quantity)) {
            throw new UnknownUnitException("There is no quantity '{$quantity}'.");
        }

        $fromUser = $this->preferenceOf($user?->unit_preferences, $quantity);

        if ($fromUser !== null) {
            return $fromUser;
        }

        if ($project !== null) {
            $config = ProjectPipingConfig::query()->where('project_id', $project->id)->first();
            $fromProject = $this->preferenceOf($config?->unit_preferences, $quantity);

            if ($fromProject !== null) {
                return $fromProject;
            }
        }

        // The pack's default DISPLAY unit, not its canonical one. kPa is how
        // pressure is stored; bar is how it is read.
        return $this->units->defaultDisplayUnit($quantity);
    }

    /**
     * Every quantity resolved at once, for a view that renders a whole table.
     *
     * @return array<string, string>
     */
    public function displayUnits(?Project $project = null, ?User $user = null): array
    {
        $out = [];

        foreach (Quantity::all() as $quantity) {
            $out[$quantity] = $this->displayUnit($quantity, $project, $user);
        }

        return $out;
    }

    /** Set the project-wide defaults. Employer only, and not on an archive. */
    public function setProjectPreferences(Project $project, User $actor, array $preferences): ProjectPipingConfig
    {
        $this->archives->assertWritable($project);

        if ($actor->company_id !== $project->owner_company_id) {
            throw ValidationException::withMessages([
                'units' => __('epcc.units.owner_only'),
            ]);
        }

        $clean = $this->validated($preferences);

        $config = ProjectPipingConfig::query()->where('project_id', $project->id)->first();

        if ($config === null) {
            $config = ProjectPipingConfig::create([
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'line_number_pattern' => ProjectPipingConfig::defaultPattern(),
                'services' => [],
                'coatings' => [],
                'insulation_types' => [],
                'unit_preferences' => $clean,
            ]);

            return $config;
        }

        $config->update(['unit_preferences' => $clean]);

        return $config->fresh();
    }

    /**
     * Set a user's personal display preference.
     *
     * Deliberately NOT gated on the archive or on company: this changes
     * nothing about the stored data, only how one person reads it. Blocking a
     * contractor from viewing a finished project in their own units would be
     * protecting nothing.
     */
    public function setUserPreferences(User $user, array $preferences): User
    {
        $clean = $this->validated($preferences);

        $value = $clean === [] ? null : $clean;

        $user->update(['unit_preferences' => $value]);

        // NOT $user->fresh(): `users` carries RLS, and a bare re-read outside a
        // tenant scope returns null even though the write succeeded. The
        // in-memory model is already correct after update(), so refresh the
        // attribute rather than re-querying a row we may not be scoped to see.
        $user->setAttribute('unit_preferences', $value);
        $user->syncOriginal();

        return $user;
    }

    /**
     * Validate a {quantity: unit} map.
     *
     * @return array<string, string>
     */
    private function validated(array $preferences): array
    {
        $clean = [];

        foreach ($preferences as $quantity => $unit) {
            $quantity = (string) $quantity;
            $unit = trim((string) $unit);

            if ($unit === '') {
                // An empty selection is "no preference", which is how a user
                // goes back to inheriting rather than being stuck with a
                // choice they cannot unmake.
                continue;
            }

            if (! Quantity::isKnown($quantity)) {
                throw ValidationException::withMessages([
                    'units' => __('epcc.units.unknown_quantity', ['name' => $quantity]),
                ]);
            }

            try {
                $resolved = $this->units->resolve($unit);
            } catch (UnknownUnitException $e) {
                throw ValidationException::withMessages(['units' => $e->getMessage()]);
            }

            if ($resolved['quantity'] !== $quantity) {
                // Choosing psi for TEMPERATURE would render every temperature
                // as a pressure. The pairing has to be checked, not just the
                // unit's existence.
                throw ValidationException::withMessages([
                    'units' => __('epcc.units.wrong_quantity', [
                        'unit' => $resolved['code'],
                        'belongs' => $resolved['quantity'],
                        'chosen' => $quantity,
                    ]),
                ]);
            }

            if (! $this->units->isSelectable($resolved['code'])) {
                // The unit is real and still converts - it is simply not on
                // the menu. Saying so beats "unknown unit", which would be a
                // lie about a unit the engine can convert perfectly well.
                throw ValidationException::withMessages([
                    'units' => __('epcc.units.not_selectable', [
                        'unit' => $resolved['code'],
                        'allowed' => implode(', ', array_map(
                            static fn (array $u): string => (string) $u['code'],
                            $this->units->selectableUnitsFor($resolved['quantity']),
                        )),
                    ]),
                ]);
            }

            $clean[$quantity] = $resolved['code'];
        }

        return $clean;
    }

    /** @param array<string, mixed>|null $prefs */
    private function preferenceOf(?array $prefs, string $quantity): ?string
    {
        $value = $prefs[$quantity] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // A preference written when the pack had a unit that has since been
        // removed must not break the screen - fall through to the next layer.
        return $this->units->knows($value) ? $this->units->resolve($value)['code'] : null;
    }
}
