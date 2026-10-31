<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Piece-level custody — ADR-048 A.4b.
 *
 * Every question about material that ARRIVED was already answerable: custody
 * knows the heat, the GRN knows the number, the MTR binding knows which
 * certificate covers which receipt. Every question about joints that were
 * WELDED was answerable too. What was missing is the sentence between them:
 *
 *     this piece was cut from that receipt.
 *
 * Without it a dossier can list the certificates a project holds, but it cannot
 * say one of them covers the steel in front of the client — and listing
 * certificates next to a joint without proving the connection is exactly the
 * padding the dossier screen warns against.
 *
 * The link lives on the COMPONENT because the component is the piece: a length
 * of pipe, an elbow, a flange. A joint joins two pieces, so a joint's parent
 * metal is the material of its two components.
 *
 * The service is thin by design: it shapes input, maps refusals to bilingual
 * field errors, and reads the two questions a caller asks. Every rule — whose
 * claim it is, where the heat comes from, what makes a link honest — lives in
 * the database.
 *
 * Declared boundary: this is a statement about IDENTITY, not quantity. How much
 * of a receipt was consumed, and whether that overruns what arrived, needs an
 * issuance model the project has not declared.
 */
final class ComponentMaterialService
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Runs $fn under both session contracts the guard needs: the results
     * register gates the claim itself, and the custody chain it reads is only
     * visible to a receipt actor — so a linker who cannot see the receipt could
     * never trace a piece to it.
     */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $priorResult = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;
            $priorReceipt = DB::selectOne("SELECT current_setting('epcc.receipt_actor_id', true) AS v")->v;

            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$actor->id]);
            DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$actor->id]);

            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$priorResult ?? '']);
                DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$priorReceipt ?? '']);
            }
        }, $project->id);
    }

    /**
     * States what one component was made from.
     *
     * @param  array<string,mixed>  $input  custody_id?, heat_ref? (observed only), lot_ref?, unlinked_reason?, reason, request_id
     *
     * @return array{id: string, component_id: string, custody_id: ?string, link: string, heat_ref: string, lot_ref: ?string, sequence: int}
     */
    public function linkMaterial(Project $project, User $actor, string $componentId, array $input, string $lang = 'fa'): array
    {
        $errors = [];

        $component = $this->uuidArg($componentId, 'component_id', $errors, $lang);

        $custodyId = null;
        $rawCustody = trim((string) ($input['custody_id'] ?? ''));
        if ($rawCustody !== '') {
            $custodyId = $this->uuidArg($rawCustody, 'custody_id', $errors, $lang);
        }

        $heat = trim((string) ($input['heat_ref'] ?? ''));
        if ($heat !== '' && mb_strlen($heat) > 200) {
            $errors['heat_ref'] = $this->msg($lang, 'شناسهٔ heat باید حداکثر ۲۰۰ کاراکتر باشد.', 'The heat reference must be at most 200 characters.');
        }

        $lot = trim((string) ($input['lot_ref'] ?? ''));
        if ($lot !== '' && mb_strlen($lot) > 200) {
            $errors['lot_ref'] = $this->msg($lang, 'شناسهٔ lot باید حداکثر ۲۰۰ کاراکتر باشد.', 'The lot reference must be at most 200 characters.');
        }
        if ($custodyId !== null && $lot !== '') {
            // The lot is the custody chain's own claim. A caller that supplies
            // one alongside a receipt is asserting something the receipt may
            // not attest; the guard would overwrite it, which is worse than a
            // refusal because the caller's screen would still show their value.
            $errors['lot_ref'] = $this->msg($lang,
                'وقتی رسید مشخص است، lot از زنجیرهٔ custody خوانده می‌شود و نباید اعلام شود.',
                'When a custody record is named, the lot is read from the custody chain and must not be declared.');
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 4000) {
            $errors['reason'] = $this->msg($lang, 'دلیلِ این اتصال الزامی است و باید حداکثر ۴۰۰۰ کاراکتر باشد.', 'A reason is required and must be at most 4000 characters.');
        }

        $unlinked = trim((string) ($input['unlinked_reason'] ?? ''));
        if ($unlinked !== '' && mb_strlen($unlinked) > 4000) {
            $errors['unlinked_reason'] = $this->msg($lang, 'دلیلِ بی‌اتصالی باید حداکثر ۴۰۰۰ کاراکتر باشد.', 'The unlinked reason must be at most 4000 characters.');
        }

        $requestId = trim((string) ($input['request_id'] ?? ''));
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $component, $custodyId, $heat, $lot, $reason, $unlinked, $requestId, $lang): array {
            try {
                $id = (string) DB::table('component_material_links')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'component_id' => $component,
                    'custody_id' => $custodyId,
                    // Everything below is replaced by the guard from the
                    // custody chain; the caller states none of it.
                    'custody_amendment_id' => null,
                    'link' => 'OBSERVED',
                    'heat_ref' => $heat,
                    'lot_ref' => $lot === '' ? null : $lot,
                    'linked_policy_id' => $component,
                    'linked_by' => $actor->id,
                    'sequence' => 1,
                    'unlinked_reason' => $unlinked === '' ? null : $unlinked,
                    'reason' => $reason,
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }

            $row = DB::table('component_material_links')->where('id', $id)->first();

            return [
                'id' => $id,
                'component_id' => (string) $row->component_id,
                'custody_id' => $row->custody_id === null ? null : (string) $row->custody_id,
                'link' => (string) $row->link,
                'heat_ref' => (string) $row->heat_ref,
                'lot_ref' => $row->lot_ref === null ? null : (string) $row->lot_ref,
                'sequence' => (int) $row->sequence,
            ];
        });
    }

    /** What this piece is made of, as the chain says today. Null when nobody has said. */
    public function effective(Project $project, User $actor, string $componentId): ?array
    {
        if (! Str::isUuid($componentId)) {
            return null;
        }

        $row = $this->scoped($project, $actor, fn () => DB::selectOne(
            'SELECT epcc_component_effective_material(?)::text AS m',
            [$componentId],
        ));

        return $row === null || $row->m === null ? null : json_decode($row->m, true);
    }

    /**
     * The material identity of one joint: the parent metal on each side and the
     * filler lot. This is the sentence the dossier needs — everything else about
     * a joint is already there.
     */
    public function jointMaterial(Project $project, User $actor, string $jointId): ?array
    {
        if (! Str::isUuid($jointId)) {
            return null;
        }

        $row = $this->scoped($project, $actor, fn () => DB::selectOne(
            'SELECT epcc_joint_material_identity(?)::text AS m',
            [$jointId],
        ));

        return $row === null || $row->m === null ? null : json_decode($row->m, true);
    }

    /**
     * The turnover question this slice makes answerable: for the pieces handed
     * over, which heats are they, is each one traced to a receipt, and does a
     * certificate cover it.
     *
     * A heat with no certificate is reported, never omitted — an installed heat
     * nobody can certify is the finding, not an absence of data.
     *
     * @param  list<string>  $componentIds
     * @return array{pieces: int, traced: int, untraced: list<string>, heats: list<array<string,mixed>>, unattested: list<string>}
     */
    public function heatCoverage(Project $project, User $actor, array $componentIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $componentIds), fn (string $v): bool => Str::isUuid($v)));

        if ($ids === []) {
            return ['pieces' => 0, 'traced' => 0, 'untraced' => [], 'heats' => [], 'unattested' => []];
        }

        return $this->scoped($project, $actor, function () use ($project, $ids): array {
            $rows = DB::select(
                'SELECT c.id::text AS component_id, epcc_component_effective_material(c.id) AS m
                   FROM components c
                  WHERE c.id = ANY(string_to_array(?, \',\')::uuid[])
                  ORDER BY c.sequence, c.id',
                [implode(',', $ids)],
            );

            $heats = [];
            $untraced = [];
            $traced = 0;

            foreach ($rows as $row) {
                if ($row->m === null) {
                    $untraced[] = (string) $row->component_id;
                    continue;
                }

                $m = json_decode($row->m, true);
                $heat = (string) $m['heat_ref'];
                $traced++;

                if (! isset($heats[$heat])) {
                    $heats[$heat] = [
                        'heat_ref' => $heat,
                        'lot_ref' => $m['lot_ref'] ?? null,
                        'pieces' => 0,
                        'custody_id' => $m['custody_id'] ?? null,
                        'link' => $m['link'] ?? null,
                        'certificates' => 0,
                    ];
                }

                $heats[$heat]['pieces']++;
            }

            if ($heats !== []) {
                $bound = DB::select(
                    'SELECT heat_ref, count(*)::int AS n
                       FROM material_certificate_bindings
                      WHERE project_id = ? AND custody_id IS NOT NULL AND heat_ref = ANY(?)
                      GROUP BY heat_ref',
                    [$project->id, '{' . implode(',', array_keys($heats)) . '}'],
                );

                foreach ($bound as $row) {
                    if (isset($heats[$row->heat_ref])) {
                        $heats[$row->heat_ref]['certificates'] = (int) $row->n;
                    }
                }
            }

            $unattested = [];
            foreach ($heats as $heat) {
                if ($heat['certificates'] === 0) {
                    $unattested[] = $heat['heat_ref'];
                }
            }

            return [
                'pieces' => count($rows),
                'traced' => $traced,
                'untraced' => $untraced,
                'heats' => array_values($heats),
                'unattested' => $unattested,
            ];
        });
    }

    public function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();

        $text = match (true) {
            str_contains($m, 'COMPONENT_MATERIAL_NOT_NOMINATED') => $this->msg($lang,
                'برای اظهارِ متریالِ یک قطعه باید نامزدِ «link_component_material» باشید.',
                'Stating what a piece is made of requires a nomination for it.'),
            str_contains($m, 'COMPONENT_MATERIAL_COMPONENT_NOT_FOUND') => $this->msg($lang,
                'این قطعه در این پروژه یافت نشد.',
                'The component was not found in this project.'),
            str_contains($m, 'COMPONENT_MATERIAL_CUSTODY_NOT_FOUND') => $this->msg($lang,
                'این رسید در custody این پروژه یافت نشد.',
                'That custody record was not found in this project.'),
            str_contains($m, 'COMPONENT_MATERIAL_CUSTODY_NO_HEAT') => $this->msg($lang,
                'این رسید هیچ heatی را تأیید نکرده است؛ قطعه را نمی‌توان به آن منتسب کرد.',
                'That receipt attests no heat, so a piece cannot be traced to it.'),
            str_contains($m, 'COMPONENT_MATERIAL_HEAT_REQUIRED') => $this->msg($lang,
                'وقتی رسیدی مشخص نیست، heat باید اعلام شود.',
                'With no custody record named, the heat must be stated.'),
            str_contains($m, 'COMPONENT_MATERIAL_REASON_REQUIRED') => $this->msg($lang,
                'قطعه‌ای که به رسید متصل نیست باید دلیلِ بی‌اتصالی داشته باشد.',
                'A piece with no custody record must say why it is untraced.'),
            str_contains($m, 'COMPONENT_MATERIAL_AUTHORITY') => $this->msg($lang,
                'اتصالِ متریال باید به نامِ شرکت و شخصِ جاری باشد.',
                'A material link must be made by the current company and actor.'),
            str_contains($m, 'component_material_custody_unique') => $this->msg($lang,
                'این قطعه پیش‌تر به همین رسید منتسب شده است.',
                'This piece is already traced to that custody record.'),
            str_contains($m, 'component_material_request_unique') => $this->msg($lang,
                'این درخواست قبلاً ثبت شده است؛ اتصال تکراری ثبت نمی‌شود.',
                'That request id was already recorded; the link was not duplicated.'),
            str_contains($m, 'component_material_traceability_check') => $this->msg($lang,
                'پیوند باید یا به رسید متصل باشد یا دلیلِ بی‌اتصالی داشته باشد.',
                'A link must either name a custody record or state why it does not.'),
            default => $this->msg($lang, 'ثبتِ پیوندِ متریال پذیرفته نشد.', 'The material link was refused.'),
        };

        return ValidationException::withMessages(['material' => $text]);
    }

    private function uuidArg(string $value, string $field, array &$errors, string $lang): ?string
    {
        $v = trim($value);
        if (! Str::isUuid($v)) {
            $errors[$field] = $this->msg($lang, 'شناسه معتبر نیست.', 'The identifier is not valid.');

            return null;
        }

        return $v;
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'en' ? $en : $fa;
    }
}
