<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MaterialInspection;
use App\Models\MaterialItem;
use App\Models\MaterialLot;
use App\Models\MaterialMovement;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Services\InspectionAccessService;
use App\Services\InspectionEntryService;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionDetailsService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\ProcurementService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\PurchaseOrderPricingService;
use App\Services\PurchaseOrderSourceReviewService;
use App\Services\WarehouseService;
use App\Support\AuthorizationPipeline;
use App\Support\JalaliDate;
use App\Support\MovementType;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * تهیه و انبار - procurement and materials.
 *
 * Two screens:
 *   /materials     the warehouse: lots, balances, movements, the MC shortage
 *   /procurement   requisitions and purchase orders
 *
 * Both read through TenantContext::runAs so RLS decides what each company
 * sees. The vendor case matters: a vendor signing in sees its own purchase
 * orders and nothing else, and that is enforced in the database rather than
 * by a where-clause somebody could forget.
 */
final class MaterialsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly ProcurementService $proc,
        private readonly PurchaseOrderAccessService $poAccess,
        private readonly PurchaseOrderExecutionAccessService $poExecutionAccess,
        private readonly PurchaseOrderExecutionService $poExecution,
        private readonly PurchaseOrderPricingService $poPricing,
        private readonly PurchaseOrderSourceReviewService $poSourceReview,
        private readonly PurchaseOrderEntryService $poEntry,
        private readonly WarehouseService $wh,
        private readonly MaterialRequisitionAccessService $mrAccess,
        private readonly InspectionAccessService $inspectionAccess,
        private readonly InspectionEntryService $inspectionEntry,
        private readonly MaterialRequisitionEntryService $mrEntry,
        private readonly ProjectFittingDefinitionService $projectAccess,
    ) {}

    // -----------------------------------------------------------------------
    // Warehouse
    // -----------------------------------------------------------------------

    public function warehouse(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$lots, $movements, $subsystems, $status, $flagged] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project): array {
                $lots = MaterialLot::with(['movements', 'purchaseOrder', 'subsystem', 'mtcDocument'])
                    ->orderBy('lot_code')
                    ->get();

                $movements = MaterialMovement::with(['lot', 'subsystem', 'performer'])
                    ->orderByDesc('sequence')
                    ->limit(60)
                    ->get();

                $subsystems = Subsystem::orderBy('code')->get();

                /*
                 * The MC link. Computed per subsystem here rather than in the
                 * view, so the template never has to know what "short" means.
                 */
                $status = $subsystems->mapWithKeys(fn (Subsystem $s) => [
                    $s->id => $this->wh->subsystemMaterialStatus($s),
                ]);

                $flagged = $this->wh->uninspectedLots($project);

                return [$lots, $movements, $subsystems, $status, $flagged];
            },
            $project->id,
        );

        return view('materials.warehouse', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'lots' => $lots,
            'movements' => $movements,
            'subsystems' => $subsystems,
            'materialStatus' => $status,
            'flagged' => $flagged,
            'movementTypes' => MovementType::ALL,
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** خروج از انبار - issue stock to a subsystem. */
    public function issue(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'lot_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'subsystem_id' => ['nullable', 'uuid'],
            'to_location' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $project, $user, $lang): void {
            $lot = MaterialLot::findOrFail($data['lot_id']);
            $subsystem = isset($data['subsystem_id']) && $data['subsystem_id'] !== null
                ? Subsystem::find($data['subsystem_id'])
                : null;

            $this->wh->issue(
                $project, $user, $lot, (float) $data['quantity'],
                $subsystem, $data['to_location'] ?? null, $data['note'] ?? null, $lang,
            );
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'خروج از انبار ثبت شد.'
            : 'Material issued from the warehouse.');
    }

    /** ورود به سایت - record arrival at the subsystem. */
    public function deliver(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'lot_id' => ['required', 'uuid'],
            'subsystem_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $project, $user, $lang): void {
            $lot = MaterialLot::findOrFail($data['lot_id']);
            $subsystem = Subsystem::findOrFail($data['subsystem_id']);

            $this->wh->deliverToSite(
                $project, $user, $lot, $subsystem, (float) $data['quantity'],
                $data['note'] ?? null, $lang,
            );
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'ورود متریال به سایت ثبت شد.'
            : 'Site delivery recorded.');
    }

    /** Correct a mistake without editing history. */
    public function reverse(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'movement_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $user, $lang): void {
            $movement = MaterialMovement::findOrFail($data['movement_id']);
            $this->wh->reverse($movement, $user, $data['reason'], $lang);
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'سند ابطال ثبت شد؛ سند اصلی دست‌نخورده باقی ماند.'
            : 'A reversing entry was posted; the original is untouched.');
    }

    // -----------------------------------------------------------------------
    // Procurement
    // -----------------------------------------------------------------------

    public function requisition(Request $request, string $projectCode, string $requisitionId): View
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $lang = $this->lang($request);
        $data = app(MaterialRequisitionDetailsService::class)->read($project, $request->user(), $requisitionId);

        return view('materials.requisition', $data + [
            'project' => $project, 'user' => $request->user(), 'acting' => $request->user()->company,
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Read-only order sheet. Every relation is loaded inside the project RLS context. */
    public function purchaseOrder(Request $request, string $projectCode, string $orderId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);
        $this->projectAccess->assertAccess($project, $user);

        abort_unless($user->company?->plan?->hasModule('proc') && $user->company->plan->allowsPack($project->standards_pack), 403);

        $order = $this->tenant->runAs($user->company_id, fn () => PurchaseOrder::query()
            ->where('project_id', $project->id)
            ->with(['vendor', 'company', 'requisition',
                'items' => fn ($q) => $q->orderBy('line_no')->orderBy('created_at')->orderBy('id'),
                'items.subsystem', 'items.requisitionItem',
                'lots' => fn ($q) => $q->orderBy('lot_code'),
                'lots.movements', 'lots.purchaseOrderItem',
                'inspections' => fn ($q) => $q->orderByDesc('sequence'),
                'inspections.inspector'])
            ->findOrFail($orderId), $project->id);

        return view('materials.purchase-order', [
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user, 'acting' => $user->company, 'project' => $project,
            'order' => $order, 'jalaliDate' => $this->today($lang),
            'canRecordInspection' => $this->inspectionAccess->allows($project, $user, $order->discipline),
        ] + $this->poPricing->context($project, $user, $order));
    }

    public function purchaseOrderSourceReview(Request $request, string $projectCode, string $orderId): View
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $lang = $this->lang($request);
        $data = $this->poSourceReview->review($project, $request->user(), $orderId);

        return view('materials.po-source-review', $data + [
            'project' => $project, 'user' => $request->user(), 'acting' => $request->user()->company,
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'jalaliDate' => $this->today($lang),
        ]);
    }

    public function pricePurchaseOrder(Request $request, string $projectCode, string $orderId): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['pricing' => ['required', 'array']]);
        $this->poPricing->price($project, $request->user(), $orderId, $request->input('pricing'));

        return redirect()->route('procurement.orders.show', ['projectCode' => $projectCode, 'orderId' => $orderId, 'lang' => $this->lang($request)])
            ->with('status', __('po_pricing.saved'));
    }

    public function procurement(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);
        $this->projectAccess->assertAccess($project, $user);
        abort_unless($user->company?->plan?->hasModule('proc') && $user->company->plan->allowsPack($project->standards_pack), 403);

        $validated = $request->validate([
            'material_category' => ['nullable', Rule::in([...MaterialItem::categories(), 'UNCLASSIFIED'])],
            'mr_q' => ['nullable', 'string', 'max:120'], 'po_q' => ['nullable', 'string', 'max:120'],
            'mr_state' => ['nullable', 'string', Rule::in(ProcurementState::MR_ALL)],
            'po_state' => ['nullable', 'string', Rule::in(ProcurementState::PO_ALL)],
            'mr_page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'po_page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'inspection_page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $categoryFilter = $validated['material_category'] ?? null;
        $registerFilters = [];
        foreach (['mr_q', 'po_q', 'mr_state', 'po_state'] as $key) {
            $registerFilters[$key] = trim($validated[$key] ?? '');
        }

        [$requisitions, $orders, $inspections, $vendors, $mrAuthorAccess, $canRaiseMr, $mrSubsystems, $poAuthorAccess, $canRaisePo, $poExecutionAccess, $lateOrderCount, $inspectionAuthorAccess] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project, $user, $categoryFilter, $registerFilters, $validated): array {
                $requisitions = MaterialRequisition::with(['items.subsystem', 'company'])
                    ->where('project_id', $project->id)
                    ->when($categoryFilter, fn ($q) => $categoryFilter === 'UNCLASSIFIED' ? $q->whereNull('material_category') : $q->where('material_category', $categoryFilter))
                    ->when($registerFilters['mr_state'] !== '', fn ($q) => $q->where('state', $registerFilters['mr_state']))
                    ->when($registerFilters['mr_q'] !== '', fn ($q) => $this->searchRegister($q, $registerFilters['mr_q']))
                    ->orderByDesc('sequence')->orderByDesc('id')
                    ->paginate(25, ['*'], 'mr_page', (int) ($validated['mr_page'] ?? 1))->withQueryString();

                $ordersQuery = PurchaseOrder::with(['items', 'vendor', 'requisition'])
                    ->where('project_id', $project->id)
                    ->when($categoryFilter, fn ($q) => $categoryFilter === 'UNCLASSIFIED' ? $q->whereNull('material_category') : $q->where('material_category', $categoryFilter))
                    ->when($registerFilters['po_state'] !== '', fn ($q) => $q->where('state', $registerFilters['po_state']))
                    ->when($registerFilters['po_q'] !== '', fn ($q) => $this->searchRegister($q, $registerFilters['po_q']))
                    ->orderByDesc('sequence')->orderByDesc('id');
                // Same filtered/RLS-capped result set as the PO paginator, not just its current page.
                $lateOrderCount = (clone $ordersQuery)->whereNotNull('promised_date')->whereNotNull('forecast_date')
                    ->whereColumn('forecast_date', '>', 'promised_date')->count();
                $orders = $ordersQuery->paginate(25, ['*'], 'po_page', (int) ($validated['po_page'] ?? 1))->withQueryString();

                $inspections = MaterialInspection::with('company')
                    ->where('project_id', $project->id)
                    ->orderByDesc('sequence')->orderByDesc('id')
                    ->paginate(25, ['*'], 'inspection_page', (int) ($validated['inspection_page'] ?? 1))->withQueryString();

                $participantIds = DB::table('project_company')
                    ->where('project_id', $project->id)
                    ->pluck('company_id');

                $vendors = Company::whereIn('id', $participantIds)
                    ->where('tenant_type', 'VEN')
                    ->orderBy('code')
                    ->get();

                return [$requisitions, $orders, $inspections, $vendors, $this->mrAccess->dashboard($project, $user), $this->mrAccess->allows($project, $user), Subsystem::where('project_id', $project->id)->orderBy('code')->get(), $this->poAccess->dashboard($project, $user), $this->poAccess->allows($project, $user), $this->poExecutionAccess->dashboard($project, $user), $lateOrderCount, $this->inspectionAccess->dashboard($project, $user)];
            },
            $project->id,
        );

        return view('materials.procurement', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'requisitions' => $requisitions,
            'orders' => $orders,
            'categoryFilter' => $categoryFilter,
            'registerFilters' => $registerFilters,
            'lateOrderCount' => $lateOrderCount,
            'inspectionAuthorAccess' => $inspectionAuthorAccess,
            'poAuthorAccess' => $poAuthorAccess, 'canRaisePo' => $canRaisePo,
            'poExecutionAccess' => $poExecutionAccess,
            'inspections' => $inspections,
            'vendors' => $vendors,
            'mrAuthorAccess' => $mrAuthorAccess,
            'canRaiseMr' => $canRaiseMr,
            'mrSubsystems' => $mrSubsystems,
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Header-only literal substring search, grouped so OR cannot escape project/category/RLS scope. */
    private function searchRegister(Builder $query, string $text): Builder
    {
        return $query->where(function (Builder $search) use ($text): void {
            $search->whereRaw("strpos(lower(coalesce(number, '')), lower(?)) > 0", [$text])
                ->orWhereRaw("strpos(lower(coalesce(title_en, '')), lower(?)) > 0", [$text])
                ->orWhereRaw("strpos(lower(coalesce(title_fa, '')), lower(?)) > 0", [$text]);
        });
    }

    public function createRequisition(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['mr' => ['required', 'array']]);
        $mr = $this->mrEntry->create($project, $request->user(), $request->input('mr'), $this->lang($request));

        return redirect()->route('procurement', ['projectCode' => $project->code, 'lang' => $this->lang($request)])
            ->with('status', __('mr.created', ['number' => $mr->number]));
    }

    public function createPurchaseOrder(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['po' => ['required', 'array']]);
        $po = $this->poEntry->create($project, $request->user(), $request->input('po'), $this->lang($request));

        return redirect()->route('procurement.orders.show', ['projectCode' => $project->code, 'orderId' => $po->id, 'lang' => $this->lang($request)])
            ->with('status', __('po_entry.created', ['number' => $po->number]));
    }

    public function createPurchaseOrderFromRequisition(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['po_source' => ['required', 'array']]);
        $po = $this->poEntry->createFromRequisition($project, $request->user(), $request->input('po_source'), $this->lang($request));

        return redirect()->route('procurement.orders.show', ['projectCode' => $project->code, 'orderId' => $po->id, 'lang' => $this->lang($request)])
            ->with('status', __('po_source.created', ['number' => $po->number]));
    }

    public function purchaseOrderExecutionWorkspace(Request $request, string $projectCode, string $buyerId, ?string $orderId = null): View
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $lang = $this->lang($request);
        $request->validate(['category' => ['nullable', 'string', Rule::in(MaterialItem::categories())]]);
        $data = $this->poExecution->workspace($project, $request->user(), $buyerId, $orderId, $request->query('category'));

        return view('materials.po-execution', $data + [
            'project' => $project, 'user' => $request->user(), 'acting' => $request->user()->company,
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'jalaliDate' => $this->today($lang),
        ]);
    }

    public function executePurchaseOrder(Request $request, string $projectCode, string $buyerId, string $orderId, string $purpose): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['execution' => ['required', 'array']]);
        $this->poExecution->act($project, $request->user(), $buyerId, $orderId, $purpose, $request->input('execution'));

        return redirect()->route('procurement.execution.order', ['projectCode' => $projectCode, 'buyerId' => $buyerId, 'orderId' => $orderId, 'lang' => $this->lang($request)])
            ->with('status', __('po_execution.saved_'.$purpose));
    }

    public function configurePurchaseOrderExecutionAccess(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['execution_access' => ['required', 'array']]);
        $this->poExecutionAccess->configure($project, $request->user(), $request->input('execution_access'));

        return redirect()->route('procurement', ['projectCode' => $project->code, 'lang' => $this->lang($request)])
            ->with('status', __('po_execution_access.saved'));
    }

    public function configurePurchaseOrderAuthors(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['po_access' => ['required', 'array']]);
        $this->poAccess->configure($project, $request->user(), $request->input('po_access'));

        return redirect()->route('procurement', ['projectCode' => $project->code, 'lang' => $this->lang($request)])
            ->with('status', __('po_entry.access_saved'));
    }

    public function configureInspectionAuthors(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['inspection_access' => ['required', 'array']]);
        $this->inspectionAccess->configure($project, $request->user(), $request->input('inspection_access'));

        return redirect()->route('procurement', ['projectCode' => $projectCode, 'lang' => $this->lang($request)])
            ->with('status', __('inspection_entry.access_saved'));
    }

    public function recordInspection(Request $request, string $projectCode, string $orderId): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['inspection' => ['required', 'array']]);
        $this->inspectionEntry->create($project, $request->user(), $orderId, $request->input('inspection'));

        return redirect()->route('procurement.orders.show', ['projectCode' => $projectCode, 'orderId' => $orderId, 'lang' => $this->lang($request)])
            ->with('status', __('inspection_entry.saved'));
    }

    public function configureRequisitionAuthors(Request $request, string $projectCode): RedirectResponse
    {
        $project = $this->resolveProject($request->user(), $projectCode);
        $request->validate(['access' => ['required', 'array']]);
        $this->mrAccess->configure($project, $request->user(), $request->input('access'));

        return redirect()->route('procurement', ['projectCode' => $project->code, 'lang' => $this->lang($request)])
            ->with('status', __('mr.access_saved'));
    }

    /** Move a requisition one step along. */
    public function advanceRequisition(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'requisition_id' => ['required', 'uuid'],
            'action' => ['required', 'string', 'in:submit,technical,commercial,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $user, $lang): void {
            $mr = MaterialRequisition::findOrFail($data['requisition_id']);

            match ($data['action']) {
                'submit' => $this->proc->submitRequisition($mr, $user, $lang),
                'technical' => $this->proc->approveTechnically($mr, $user, $lang),
                'commercial' => $this->proc->approveCommercially($mr, $user, $lang),
                'reject' => $this->proc->rejectRequisition($mr, $user, (string) ($data['reason'] ?? ''), $lang),
            };
        }, $project->id);

        return back()->with('status', $lang === 'fa' ? 'وضعیت درخواست به‌روز شد.' : 'Requisition updated.');
    }

    /** Record what the vendor now forecasts, without touching the promise. */
    public function forecast(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'purchase_order_id' => ['required', 'uuid'],
            'forecast_date' => ['required', 'date'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data): void {
            $po = PurchaseOrder::findOrFail($data['purchase_order_id']);
            $this->proc->forecast($po, $data['forecast_date']);
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'تاریخ پیش‌بینی به‌روز شد؛ تاریخ تعهد قراردادی تغییر نکرد.'
            : 'Forecast updated; the contractual promise is unchanged.');
    }

    // -----------------------------------------------------------------------

    private function lang(Request $request): string
    {
        $lang = (string) $request->query('lang', $request->session()->get('lang', 'fa'));

        return in_array($lang, ['fa', 'en'], true) ? $lang : 'fa';
    }

    private function resolveProject($user, string $code): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', $code)->firstOrFail()
        );
    }

    private function today(string $lang): string
    {
        return JalaliDate::format(now(), $lang);
    }
}
