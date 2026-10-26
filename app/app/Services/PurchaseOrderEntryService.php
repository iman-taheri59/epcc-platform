<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** ADR-033. Human DRAFT entry only; never consumes/approves an MR or issues a PO. */
final class PurchaseOrderEntryService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $scope,
        private readonly ProjectArchiveService $archives,
        private readonly ProcurementCategoryService $categories,
        private readonly PurchaseOrderAccessService $access,
        private readonly ProcurementService $procurement,
    ) {}

    public function create(Project $project, User $actor, array $input, string $lang = 'fa'): PurchaseOrder
    {
        $this->scope->assertAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $input, $lang): PurchaseOrder {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['po-entry:'.$project->id]);
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($project);
            abort_unless($this->access->allows($project, $actor), 403, __('po_entry.not_author'));
            $data = $this->validate($input, $project);
            $this->categories->validateLines($project, $data);
            $policy = $this->access->policyId($project, $actor, $data['discipline']);
            abort_unless($policy !== null, 403, __('po_entry.not_author'));
            $vendor = Company::whereKey($data['vendor_company_id'])->firstOrFail();
            // Avoid the legacy float-price calculation; persist exact decimal prices below.
            $unpriced = array_map(fn ($line) => array_diff_key($line, ['unit_price' => true]), $data['lines']);
            $po = $this->procurement->createPurchaseOrder($project, $actor, $vendor, $data['title_en'], $data['title_fa'],
                $unpriced, null, $data['currency'] ?? null, $data['promised_date'] ?? null, $lang);
            $po->material_category = $data['material_category'];
            $po->author_policy_id = $policy;
            $po->drafted_by = $actor->id;
            $po->discipline = $data['discipline'];
            $po->total_value = $data['total'];
            $po->save();
            foreach ($po->items()->orderBy('line_no')->get() as $i => $line) {
                $line->source_quantity = $data['lines'][$i]['quantity'];
                $line->material_item_id = $data['lines'][$i]['material_item_id'] ?? null;
                $line->unit_price = $data['lines'][$i]['unit_price'] ?? null;
                $line->save();
            }

            return $po->refresh();
        }), $project->id);
    }

    /** Copy an own-buyer APPROVED MR in full; never call the legacy consuming conversion. */
    public function createFromRequisition(Project $project, User $actor, array $input, string $lang = 'fa'): PurchaseOrder
    {
        $this->scope->assertAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $input, $lang): PurchaseOrder {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['po-entry:'.$project->id]);
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($project);
            abort_unless($this->access->allows($project, $actor), 403, __('po_entry.not_author'));
            Validator::make(['po_source' => $input], [
                'po_source' => ['array:requisition_id,vendor_company_id,promised_date'],
                'po_source.requisition_id' => ['required', 'uuid'],
                'po_source.vendor_company_id' => ['required', 'uuid'],
                'po_source.promised_date' => ['nullable', 'date_format:Y-m-d'],
            ])->validate();
            $mr = MaterialRequisition::where('project_id', $project->id)->where('company_id', $actor->company_id)
                ->whereKey($input['requisition_id'])->lockForUpdate()->firstOrFail();
            if (! $mr->isOrderable() || ! in_array($mr->material_category, MaterialItem::categories(), true)) {
                throw ValidationException::withMessages(['po_source.requisition_id' => __('po_source.not_eligible')]);
            }
            abort_unless($this->access->allows($project, $actor, $mr->discipline), 403, __('po_entry.not_author'));
            $sourceLines = $mr->items()->orderBy('line_no')->orderBy('id')->sharedLock()->get();
            if ($sourceLines->isEmpty() || $sourceLines->count() > 20) {
                throw ValidationException::withMessages(['po_source.requisition_id' => __('po_source.line_limit')]);
            }
            $lines = $sourceLines->map(fn ($line) => [
                'item_code' => $line->item_code, 'description_en' => $line->description_en, 'description_fa' => $line->description_fa,
                'unit' => $line->unit, 'quantity' => $line->source_quantity ?? (string) $line->quantity,
                'material_item_id' => $line->material_item_id, 'subsystem_id' => $line->subsystem_id,
            ])->all();
            $po = $this->create($project, $actor, [
                'title_en' => $mr->title_en, 'title_fa' => $mr->title_fa, 'discipline' => $mr->discipline,
                'material_category' => $mr->material_category, 'vendor_company_id' => $input['vendor_company_id'],
                'promised_date' => $input['promised_date'] ?? null, 'lines' => $lines,
            ], $lang);
            foreach ($po->items()->orderBy('line_no')->get() as $i => $line) {
                $line->requisition_item_id = $sourceLines[$i]->id;
                $line->save();
            }
            // Link only after exact line references exist. Both remain within the outer transaction.
            $po->requisition_id = $mr->id;
            $po->save();

            return $po->refresh();
        }), $project->id);
    }

    private function validate(array $input, Project $project): array
    {
        Validator::make(['po' => $input], ['po' => ['array:title_en,title_fa,discipline,material_category,vendor_company_id,currency,promised_date,lines'],
            'po.lines' => ['required', 'array', 'list', 'max:20'],
            'po.lines.*' => ['array:item_code,description_en,description_fa,unit,quantity,unit_price,subsystem_id,material_category,material_item_id']])->validate();
        $input['lines'] = array_values(array_filter($input['lines'], fn ($line) => collect($line)->contains(fn ($v) => $v !== null && $v !== '')));
        $text = static function ($key, $value, $fail): void {
            if (is_string($value) && trim($value) === '') {
                $fail(__('po_entry.blank'));
            }
        };
        $data = Validator::make($input, [
            'title_en' => ['required', 'string', 'max:255', $text], 'title_fa' => ['required', 'string', 'max:255', $text],
            'discipline' => ['required', 'string', 'max:32', 'regex:/\A[A-Z][A-Z0-9_]*\z/'],
            'material_category' => ['required', Rule::in(MaterialItem::categories())],
            'lines.*.material_category' => ['sometimes', 'required', Rule::in(MaterialItem::categories())],
            'lines.*.material_item_id' => ['nullable', 'uuid'],
            'vendor_company_id' => ['required', 'uuid'], 'currency' => ['nullable', 'string', 'regex:/\A[A-Z]{3,8}\z/'],
            'promised_date' => ['nullable', 'date_format:Y-m-d'], 'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.item_code' => ['required', 'string', 'max:64', $text],
            'lines.*.description_en' => ['required', 'string', 'max:4000', $text],
            'lines.*.description_fa' => ['required', 'string', 'max:4000', $text],
            'lines.*.unit' => ['required', 'string', 'max:16', $text],
            'lines.*.quantity' => ['bail', 'required', 'string', 'regex:/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/',
                static function ($key, $value, $fail): void {
                    if (preg_match('/[1-9]/', $value) !== 1) {
                        $fail(__('po_entry.quantity_error'));
                    }
                }],
            'lines.*.unit_price' => ['nullable', 'string', 'regex:/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/'],
            'lines.*.subsystem_id' => ['nullable', 'uuid'],
        ])->validate();
        $priced = 0;
        $total = BigDecimal::zero();
        foreach ($data['lines'] as $i => $line) {
            if (! empty($line['subsystem_id']) && ! Subsystem::where('project_id', $project->id)->whereKey($line['subsystem_id'])->exists()) {
                throw ValidationException::withMessages(["lines.$i.subsystem_id" => __('po_entry.subsystem_error')]);
            }
            if (isset($line['unit_price']) && $line['unit_price'] !== '') {
                $priced++;
                $total = $total->plus(BigDecimal::of($line['quantity'])->multipliedBy($line['unit_price']));
            } else {
                $data['lines'][$i]['unit_price'] = null;
            }
        }
        if ($priced > 0 && empty($data['currency'])) {
            throw ValidationException::withMessages(['currency' => __('po_entry.currency_required')]);
        }
        $data['total'] = null;
        if ($priced === count($data['lines'])) {
            $total = $total->toScale(2, RoundingMode::HalfUp);
            if ($total->isGreaterThan('9999999999999999.99')) {
                throw ValidationException::withMessages(['lines' => __('po_entry.total_overflow')]);
            }
            $data['total'] = (string) $total;
        }

        return $data;
    }
}
