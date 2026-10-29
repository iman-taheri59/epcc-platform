<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-048 A.4c slice 1 — two gaps the owner's roadmap carried since slice 1.
 *
 * 1. A RECORDED PRESSURE MUST BE A PRESSURE, IN A UNIT THIS PLATFORM KNOWS.
 *    The pack's *planned* pressure was unit-checked (`PipingTestPackService`
 *    calls `UnitRegistry::knows`), but the *recorded* result was only checked
 *    for non-emptiness, and `knows()` alone would happily accept "C" as a
 *    pressure — a temperature is not a pressure, and the ITR dossier prints
 *    this string next to a number a client reads. The CHECK here is generated
 *    from the same reference pack the registry reads
 *    (`standards-packs/reference/units.ref.json`), so database and application
 *    cannot drift: if the reference pack is missing we refuse to install
 *    rather than silently allow every unit.
 *
 * 2. WHO MAY AUTHOR A NOMINATION. A.4b made recording default-deny, but any
 *    project member company could still nominate its own people - and, as
 *    written, nobody had stopped a rival contractor from nominating them.
 *    Authoring is now limited to the company being nominated (self) or the
 *    project's employer/owner company, and the author must themselves be a
 *    project member. The employer path is the control that matters: the client
 *    decides who is qualified to create the evidence it will rely on.
 *
 * Forward-only: widening the unit list later is a new migration, never an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->unitCheckSql($this->pressureUnits()));
        DB::unprepared($this->policyAuthorshipSql());
    }

    /**
     * Pressure unit codes, lower-cased, straight from the reference pack the
     * UnitRegistry reads. A missing or malformed pack is an install failure,
     * not a silent "any unit goes".
     *
     * @return list<string>
     */
    private function pressureUnits(): array
    {
        $path = rtrim((string) config('epcc.reference_dir', base_path('../standards-packs/reference')), '/')
            .'/units.ref.json';

        if (! is_file($path)) {
            throw new RuntimeException("Unit reference pack not found at {$path}; cannot install the pressure-unit CHECK.");
        }

        $pack = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $units = [];

        foreach (($pack['quantities'] ?? []) as $quantity) {
            if (strtoupper((string) ($quantity['quantity'] ?? '')) !== 'PRESSURE') {
                continue;
            }
            foreach (($quantity['units'] ?? []) as $unit) {
                $code = strtolower(trim((string) ($unit['code'] ?? '')));
                if ($code !== '') {
                    $units[] = $code;
                }
            }
        }

        if ($units === []) {
            throw new RuntimeException("No PRESSURE units found in {$path}; refusing to install a check that allows nothing.");
        }

        return array_values(array_unique($units));
    }

    private function unitCheckSql(array $units): string
    {
        $list = implode(', ', array_map(
            static fn (string $u): string => "'".str_replace("'", "''", $u)."'",
            $units,
        ));

        // Case-insensitive on purpose: the service stores the uppercase typed
        // value ("BAR"), the reference pack spells it "bar", and UnitRegistry
        // normalises the same way. Silently rejecting "BAR" would be pedantry.
        return <<<SQL
ALTER TABLE test_pack_results ADD CONSTRAINT test_pack_results_pressure_unit_is_pressure
  CHECK (lower(btrim(pressure_unit)) IN ({$list}));
SQL;
    }

    private function policyAuthorshipSql(): string
    {
        return <<<'SQL'
-- You may nominate your own people, or - if you are the project's employer -
-- the people whose evidence you will rely on. Nobody else may speak for them.
CREATE OR REPLACE FUNCTION epcc_guard_result_policy_author() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE owner_company uuid;
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_POLICY_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.created_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_POLICY_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  SELECT p.owner_company_id INTO owner_company FROM projects p WHERE p.id = NEW.project_id;
  IF NEW.company_id <> NEW.target_company_id AND NEW.company_id IS DISTINCT FROM owner_company THEN
    RAISE EXCEPTION 'RESULT_POLICY_AUTHOR_NOT_PERMITTED' USING ERRCODE = '42501';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM project_memberships pm
                 WHERE pm.user_id = NEW.created_by AND pm.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_POLICY_AUTHOR_NOT_MEMBER' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;

CREATE TRIGGER result_recorder_policy_author_guard BEFORE INSERT ON result_recorder_policies
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_result_policy_author();
SQL;
    }

    public function down(): void
    {
        throw new RuntimeException('Unit-registry and policy-authorship rules are forward-only.');
    }
};
