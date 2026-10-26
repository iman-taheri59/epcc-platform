<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MaterialItem;
use App\Models\Project;
use App\Models\PurchaseOrderItem;
use App\Models\Subsystem;
use App\Services\OrderGapService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Morilog\Jalali\Jalalian;

/**
 * Order gap board (ADR-015, MTO Slice 6).
 *
 * Reads accepted gross MTO rows and effective PO quantities and reports,
 * per material: SHORTAGE / COVERED / OVER_PLAN, plus the unmatched bucket.
 * The three POST paths are the only ways attribution, amendment and
 * allocation enter the system — there is no proration anywhere.
 */
final class OrderGapController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly OrderGapService $gaps,
    ) {}

    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$board, $items, $materials, $subsystems] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project): array {
                $board = $this->gaps->board($project);

                $items = PurchaseOrderItem::with('purchaseOrder')
                    ->where('project_id', $project->id)
                    ->orderBy('item_code')
                    ->get()
                    ->filter(fn (PurchaseOrderItem $item) => ! $item->supersededBy()->exists())
                    ->values();

                $materials = MaterialItem::forProjectCatalogue($project->id)->active()
                    ->orderBy('commodity_code')
                    ->get(['id', 'commodity_code', 'description_en', 'description_fa', 'base_unit']);

                $subsystems = Subsystem::orderBy('code')->get(['id', 'code']);

                return [$board, $items, $materials, $subsystems];
            },
            $project->id,
        );

        return view('procurement.order-gap', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'jalaliDate' => $this->today($lang),
            'board' => $board['rows'],
            'totals' => $board['totals'],
            'items' => $items,
            'materials' => $materials,
            'subsystems' => $subsystems,
        ]);
    }

    public function attribute(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'material_item_id' => ['required', 'uuid'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $project, $lang): void {
            $item = PurchaseOrderItem::whereKey($data['item_id'])->firstOrFail();
            $this->gaps->humanAttribute($project, $item, $data['material_item_id'], $lang);
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'هویت متریال خط سفارش با تطبیق دقیق ثبت شد.'
            : 'The PO line was attributed by exact material identity.');
    }

    public function amend(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $lang): void {
            $item = PurchaseOrderItem::whereKey($data['item_id'])->firstOrFail();
            $this->gaps->humanAmend($item, (float) $data['quantity'], $lang);
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'اصلاحیه به‌صورت خط جدید ثبت شد؛ خط قبلی دست‌نخورده ماند.'
            : 'The amendment was recorded as a new line; the previous line is untouched.');
    }

    public function allocate(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'subsystem_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $project, $user, $lang): void {
            $item = PurchaseOrderItem::whereKey($data['item_id'])->firstOrFail();
            $this->gaps->allocate($project, $user, $item, $data['subsystem_id'], (float) $data['quantity'], $lang);
        }, $project->id);

        return back()->with('status', $lang === 'fa'
            ? 'تخصیص اسکوپ ثبت شد؛ باقی‌مانده به‌صورت تخصیص‌نیافته نمایش داده می‌شود.'
            : 'Scope allocation recorded; the remainder stays visible as unallocated.');
    }

    // -----------------------------------------------------------------

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
