<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\MaterialInspection;
use App\Models\MaterialLot;
use App\Models\MaterialMovement;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Services\ProcurementService;
use App\Services\WarehouseService;
use App\Support\AuthorizationPipeline;
use App\Support\MovementType;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Morilog\Jalali\Jalalian;

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
        private readonly WarehouseService $wh,
    ) {
    }

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

    public function procurement(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$requisitions, $orders, $inspections, $vendors] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project): array {
                $requisitions = MaterialRequisition::with(['items.subsystem', 'company'])
                    ->orderByDesc('sequence')
                    ->get();

                $orders = PurchaseOrder::with(['items', 'vendor', 'requisition'])
                    ->orderByDesc('sequence')
                    ->get();

                $inspections = MaterialInspection::with(['company', 'purchaseOrder'])
                    ->orderByDesc('sequence')
                    ->get();

                $participantIds = DB::table('project_company')
                    ->where('project_id', $project->id)
                    ->pluck('company_id');

                $vendors = Company::whereIn('id', $participantIds)
                    ->where('tenant_type', 'VEN')
                    ->orderBy('code')
                    ->get();

                return [$requisitions, $orders, $inspections, $vendors];
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
            'inspections' => $inspections,
            'vendors' => $vendors,
            'jalaliDate' => $this->today($lang),
        ]);
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
        return $lang === 'fa'
            ? Jalalian::now()->format('Y/m/d')
            : now()->format('d M Y');
    }
}
