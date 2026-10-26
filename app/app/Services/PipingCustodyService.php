<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ADR-046 physical facts only. Never calls legacy WarehouseService or performs acceptance/GRN. */
final class PipingCustodyService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ProjectArchiveService $archives) {}

    /** Scope the original caller, not the custodian/owner; restore actor GUC on nesting and failure. */
    public function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.receipt_actor_id',true) AS actor")->actor;
            DB::select("SELECT set_config('epcc.receipt_actor_id',?,true)", [$actor->id]);
            try {
                return DB::transaction($fn);
            } finally {
                DB::select("SELECT set_config('epcc.receipt_actor_id',?,true)", [$prior ?? '']);
            }
        }, $project->id);
    }

    public function policyId(Project $project, User $actor, ?string $action = null): ?string
    {
        abort_unless($action === null || in_array($action, PipingReceiptAccessService::ACTIONS, true), 403);

        return $this->scoped($project, $actor, fn () => DB::selectOne('SELECT epcc_piping_receipt_policy(?::uuid,?::uuid,?::uuid,?::text) AS id', [$project->id, $actor->id, $actor->company_id, $action])->id);
    }

    public function dashboard(Project $project, User $actor): array
    {
        return $this->scoped($project, $actor, function () use ($project, $actor): array {
            abort_unless($this->policyId($project, $actor) !== null, 403);
            $project = Project::whereKey($project->id)->firstOrFail();
            $policyId = $this->policyId($project, $actor, 'custody_mrr');
            $writable = $policyId !== null && $this->archives->isWritable($project);
            $records = DB::table('piping_custody_records')->where('project_id', $project->id)->orderByDesc('sequence')->paginate(25)->withQueryString();
            // Only original caller-visible PIPING sources; no buyer/owner impersonation or price fields.
            $sources = $writable ? DB::table('purchase_order_items as l')->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
                ->where('p.project_id', $project->id)->where('l.project_id', $project->id)->where('p.discipline', 'PIPING')
                ->orderBy('p.number')->orderBy('l.line_no')->orderBy('l.id')->limit(200)
                ->get(['l.id', 'l.line_no', 'l.unit', 'p.number']) : collect();

            return compact('project', 'records', 'sources', 'writable', 'policyId');
        });
    }

    public function detail(Project $project, User $actor, string $id): object
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $id): object {
            abort_unless($this->policyId($project, $actor) !== null, 403);
            $row = DB::table('piping_custody_records')->where('project_id', $project->id)->where('id', $id)->first();
            abort_unless($row !== null, 404);

            return $row;
        });
    }

    /** Effective source/heat overlay only; arrival facts and their fingerprint never change. */
    public function basis(Project $project, User $actor, string $id): ?array
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $id): ?array {
            $this->detail($project, $actor, $id);
            $json = DB::scalar('SELECT epcc_piping_effective_receipt(?)', [$id]);

            return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        });
    }

    public function create(Project $project, User $actor, array $input): string
    {
        try {
            return $this->scoped($project, $actor, function () use ($project, $actor, $input): string {
                // Same serialization domain as nomination replacement; re-resolve only AFTER lock waits.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$project->id]);
                $project = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $policy = $this->policyId($project, $actor, 'custody_mrr');
                abort_unless($policy !== null, 403);
                $this->archives->assertWritable($project);
                $fields = ['expected_policy_id', 'request_id', 'source_line_id', 'unlinked_reason', 'shipment_ref', 'package_ref', 'received_at', 'observed_description', 'quantity', 'unit', 'heat_ref', 'location_kind', 'location_ref', 'document_status', 'notes'];
                $rules = ['receipt' => ['required', 'array:'.implode(',', $fields)],
                    'receipt.expected_policy_id' => ['required', 'uuid'], 'receipt.request_id' => ['required', 'uuid'],
                    'receipt.source_line_id' => ['nullable', 'uuid'], 'receipt.unlinked_reason' => ['nullable', 'string', 'max:2000'],
                    'receipt.quantity' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,3})?\z/'],
                    'receipt.received_at' => ['required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\z/'],
                    'receipt.location_kind' => ['required', 'in:RECEIVING,QUARANTINE'],
                    'receipt.document_status' => ['required', 'in:MISSING,PRESENT_UNVERIFIED']];
                foreach (['shipment_ref' => 200, 'package_ref' => 200, 'observed_description' => 2000, 'unit' => 32, 'heat_ref' => 200, 'location_ref' => 200, 'notes' => 4000] as $field => $max) {
                    $rules['receipt.'.$field] = [in_array($field, ['package_ref', 'heat_ref', 'notes'], true) ? 'nullable' : 'required', 'string', 'max:'.$max];
                }
                $data = Validator::make(['receipt' => $input], $rules, array_fill_keys(['required', 'array', 'uuid', 'string', 'max', 'regex', 'in'], __('piping_custody.invalid')))->validate()['receipt'];
                foreach ($data as $key => $value) {
                    if (is_string($value)) {
                        if (str_contains($value, "\0")) {
                            $this->fail();
                        }
                        $data[$key] = trim($value);
                    }
                }
                if ($data['expected_policy_id'] !== $policy) {
                    $this->fail('stale');
                }
                foreach (['shipment_ref', 'observed_description', 'unit', 'location_ref'] as $field) {
                    if ($data[$field] === '') {
                        $this->fail();
                    }
                }
                foreach (['source_line_id', 'unlinked_reason', 'package_ref', 'heat_ref', 'notes'] as $field) {
                    $data[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
                }
                $quantity = BigDecimal::of($data['quantity'])->toScale(3);
                if ($quantity->isLessThanOrEqualTo(0)) {
                    $this->fail();
                }
                $data['quantity'] = (string) $quantity;
                try {
                    $received = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $data['received_at']);
                } catch (\Throwable) {
                    $this->fail();
                }
                if (! $received || $received->format('Y-m-d\TH:i:sP') !== $data['received_at']) {
                    $this->fail();
                }
                $data['received_at'] = $received->utc()->format('Y-m-d H:i:sP');
                if ($data['source_line_id'] === null) {
                    if ($data['unlinked_reason'] === null) {
                        $this->fail('unlinked_required');
                    }
                } else {
                    $line = DB::table('purchase_order_items as l')->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
                        ->where('l.id', $data['source_line_id'])->where('p.project_id', $project->id)->where('l.project_id', $project->id)
                        ->where('p.discipline', 'PIPING')->select('l.unit')->first();
                    if (! $line) {
                        $this->fail('source_unavailable');
                    }
                    if ($line->unit !== $data['unit'] || $data['unlinked_reason'] !== null) {
                        $this->fail('unit_or_source');
                    }
                }
                $existing = DB::table('piping_custody_records')->where('project_id', $project->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    if ($existing->created_by !== $actor->id || $existing->company_id !== $actor->company_id || $existing->policy_id !== $policy) {
                        $this->fail('retry_conflict');
                    }
                    foreach (array_diff($fields, ['expected_policy_id', 'request_id']) as $field) {
                        $old = $field === 'received_at' ? CarbonImmutable::parse($existing->$field)->utc()->format('Y-m-d H:i:sP') : $existing->$field;
                        if ($old !== $data[$field]) {
                            $this->fail('retry_conflict');
                        }
                    }

                    return $existing->id;
                }
                $id = (string) Str::uuid();
                unset($data['expected_policy_id']);
                DB::table('piping_custody_records')->insert($data + ['id' => $id, 'project_id' => $project->id, 'company_id' => $actor->company_id, 'created_by' => $actor->id, 'policy_id' => $policy]);

                return $id;
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23505') {
                $this->fail('retry_conflict');
            }
            if (str_contains($e->getMessage(), 'PIPING_CUSTODY_SOURCE')) {
                $this->fail('source_unavailable');
            }
            if (str_contains($e->getMessage(), 'PIPING_CUSTODY_UNIT')) {
                $this->fail('unit_or_source');
            }
            if (str_contains($e->getMessage(), 'PIPING_CUSTODY_NOMINATION') || str_contains($e->getMessage(), 'PIPING_CUSTODY_SCOPE')) {
                abort(403);
            }
            throw $e;
        }
    }

    private function fail(string $key = 'invalid'): never
    {
        throw ValidationException::withMessages(['receipt' => __('piping_custody.'.$key)]);
    }
}
