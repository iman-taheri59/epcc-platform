<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Project;
use App\Models\User;

/**
 * Builds the sidebar from what the company actually bought.
 *
 * The navigation is NOT a hardcoded list. It is derived from the same two
 * facts the authorization pipeline uses (ARC §5):
 *
 *   gate 2, ENTITLEMENT - does the company's plan include the module?
 *   gate 4, RBAC        - does the user hold the capability?
 *
 * so the menu can never offer something the pipeline would then refuse. A menu
 * that lies is worse than no menu: the user clicks, gets a denial, and has no
 * idea whether it is a bug, a permission problem or a billing problem.
 *
 * Three states, and the difference between them is commercially load-bearing:
 *
 *   available - entitled, built, and reachable. Rendered as a link.
 *   planned   - entitled, but the screen does not exist yet (COV-EPCC-001 puts
 *               the prototype at ~15-20% of the SRS). Rendered inert, labelled
 *               "soon". Never pretend a missing module is a locked one.
 *   locked    - the module is NOT in this company's plan. Rendered inert with
 *               the plan that would unlock it. This is the Full/Special upsell
 *               surface (SRS BR-02), so it is shown rather than hidden.
 *
 * A locked item leaks nothing: a company may always see the shape of its own
 * plan. It never reveals another tenant's data, projects or users.
 */
final class Navigation
{
    /**
     * Modules with no screen yet. Emptied as the modules land; an entry here
     * is a promise, so it must match the SRS module list exactly.
     */
    private const PLANNED_MODULES = ['awp'];

    public function __construct(private readonly AuthorizationPipeline $authz) {}

    /**
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    public function build(User $user, string $lang, string $currentPath, ?Project $project = null): array
    {
        $plan = $user->company->plan;

        $sections = [];

        $sections[] = [
            'key' => 'workspace',
            'label' => $lang === 'fa' ? 'فضای کاری' : 'Workspace',
            'items' => array_values(array_filter([
                $this->item('portfolio', 'core', '/', [
                    'fa' => 'سبد پروژه‌ها', 'en' => 'Portfolio',
                ], $plan, $lang, $currentPath),

                // Employers only. The same rule the portfolio button uses, and
                // ProjectSetupController re-checks it on GET and POST - this is
                // a convenience, never a control.
                $user->company->tenant_type === 'OWN' && $this->authz->allows($user, 'project.create')
                    ? $this->item('projects.create', 'core', '/projects/new', [
                        'fa' => 'پروژهٔ جدید', 'en' => 'New project',
                    ], $plan, $lang, $currentPath)
                    : null,
            ])),
        ];

        // Only meaningful once a project is in scope. Without one, these links
        // would have no code to point at.
        if ($project !== null) {
            $code = $project->code;

            $sections[] = [
                'key' => 'project',
                'label' => $lang === 'fa' ? 'پروژه: '.$project->code : 'Project: '.$project->code,
                'items' => [
                    $this->item('project.certificates', 'cost', "/projects/{$code}", [
                        'fa' => 'صورت‌وضعیت‌ها', 'en' => 'Certificates',
                    ], $plan, $lang, $currentPath),

                    $this->item('project.team', 'core', "/projects/{$code}/team", [
                        'fa' => 'تیم پروژه', 'en' => 'Project team',
                    ], $plan, $lang, $currentPath),

                    $this->item('project.documents', 'doc', "/projects/{$code}/documents", [
                        'fa' => 'مدارک و مدیریت اسناد', 'en' => 'Documents / CDE',
                    ], $plan, $lang, $currentPath),

                    $this->item('project.transmittals', 'doc', "/projects/{$code}/transmittals", [
                        'fa' => 'نامه‌های ارسال', 'en' => 'Transmittals',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.vendortr', 'doc', "/projects/{$code}/vendor-transmittals", [
                        'fa' => 'ترانسمیتال وندورها', 'en' => 'Vendor transmittals',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.piping.workspace', 'qaqc', "/projects/{$code}/piping/workspace", [
                        'fa' => 'فضای کار پایپینگ', 'en' => 'Piping workspace',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.piping', 'qaqc', "/projects/{$code}/piping", [
                        'fa' => 'رجیستر خطوط لوله', 'en' => 'Piping line register',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.inspection-notifications', 'qaqc', "/projects/{$code}/inspection-notifications", [
                        'fa' => 'نوتیفیکیشن بازرسی ITP', 'en' => 'ITP inspection notifications',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.punch', 'qaqc', "/projects/{$code}/punch", [
                        'fa' => 'کنترل کیفیت و پانچ‌لیست', 'en' => 'QA/QC & punch list',
                    ], $plan, $lang, $currentPath),

                    $this->item('project.procurement', 'proc', "/projects/{$code}/procurement", [
                        'fa' => 'تهیه و خرید', 'en' => 'Procurement',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.materials', 'proc', "/projects/{$code}/materials", [
                        'fa' => 'انبار و متریال', 'en' => 'Warehouse & materials',
                    ], $plan, $lang, $currentPath),
                    $this->item('project.ordergap', 'proc', "/projects/{$code}/procurement/order-gap", [
                        'fa' => 'شکاف سفارش', 'en' => 'Order gap board',
                    ], $plan, $lang, $currentPath),

                    $this->item('project.awp', 'awp', null, [
                        'fa' => 'بسته‌های کاری (AWP)', 'en' => 'Work packages (AWP)',
                    ], $plan, $lang, $currentPath),
                ],
            ];
        }

        $sections[] = [
            'key' => 'tools',
            'label' => $lang === 'fa' ? 'ابزارها' : 'Tools',
            'items' => [
                $this->item('gate', 'core', '/gate', [
                    'fa' => 'آزمایشگاه موتور قواعد', 'en' => 'Rule-engine playground',
                ], $plan, $lang, $currentPath),
            ],
        ];

        return $sections;
    }

    /**
     * @param  array{fa:string,en:string}  $label
     * @return array<string,mixed>
     */
    private function item(
        string $key,
        string $module,
        ?string $path,
        array $label,
        ?object $plan,
        string $lang,
        string $currentPath,
    ): array {
        $entitled = $plan !== null && $plan->hasModule($module);

        $state = match (true) {
            ! $entitled => 'locked',
            $path === null || in_array($module, self::PLANNED_MODULES, true) => 'planned',
            default => 'available',
        };

        return [
            'key' => $key,
            'module' => $module,
            'label' => $label[$lang],
            // A locked or planned item is inert: no href at all, so it cannot
            // be clicked, middle-clicked, or followed by a crawler.
            'url' => $state === 'available' ? $path.'?lang='.$lang : null,
            'state' => $state,
            'active' => $state === 'available' && $this->isActive($path, $currentPath),
            'badge' => match ($state) {
                'locked' => $lang === 'fa' ? 'ارتقا' : 'Upgrade',
                'planned' => $lang === 'fa' ? 'به‌زودی' : 'Soon',
                default => null,
            },
            'hint' => match ($state) {
                'locked' => $lang === 'fa'
                    ? 'ماژول «'.$module.'» در پلن فعلی شرکت شما نیست.'
                    : 'The "'.$module.'" module is not in your company plan.',
                'planned' => $lang === 'fa'
                    ? 'این ماژول هنوز ساخته نشده است.'
                    : 'This module is not built yet.',
                default => null,
            },
            'icon' => $this->icon($key),
        ];
    }

    /**
     * Exact match only.
     *
     * A prefix match would light up "Certificates" while the user is on the
     * team screen, because /projects/PH-14/team starts with /projects/PH-14.
     */
    private function isActive(?string $path, string $currentPath): bool
    {
        return $path !== null && rtrim($currentPath, '/') === rtrim($path, '/');
    }

    /** Inline SVG: the preview iframe has no network, so icon fonts/CDNs die. */
    private function icon(string $key): string
    {
        $paths = [
            'portfolio' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'projects.create' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
            'project.certificates' => '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v6h6M8.5 14.5l2 2 4-4"/>',
            'project.team' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 11.2A3 3 0 1 0 16 5.4M21.5 20a5.6 5.6 0 0 0-3.6-5.2"/>',
            'project.documents' => '<path d="M4 4h9l3 3h4v13H4z"/><path d="M8 12h8M8 16h5"/>',
            'project.transmittals' => '<path d="M3 6.5h18v11H3z"/><path d="M3 7l9 6 9-6"/>',
            'project.vendortr' => '<path d="M3 9h13v8H3z"/><path d="M16 11h3l2 3v3h-5z"/><circle cx="7" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/>',
            'project.punch' => '<path d="M9 11l2.5 2.5L16 9"/><circle cx="12" cy="12" r="9"/>',
            'project.piping' => '<path d="M3 12h18"/><path d="M7 9v6"/><path d="M17 9v6"/>',
            // A shopping-cart outline for buying, a crate for the warehouse.
            'project.procurement' => '<path d="M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.4a2 2 0 0 0 2-1.55L20.5 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/>',
            'project.materials' => '<path d="M3 8.5L12 4l9 4.5v7L12 20l-9-4.5z"/><path d="M3 8.5L12 13l9-4.5M12 13v7"/>',
            'project.ordergap' => '<path d="M3 20h18"/><path d="M5 20V10M10 20V4M15 20v-7M20 20V7"/><path d="M14 3h7v7"/>',
            'project.awp' => '<path d="M12 2.5l8.5 4.8v9.4L12 21.5 3.5 16.7V7.3z"/><path d="M3.5 7.3L12 12l8.5-4.7M12 12v9.5"/>',
            'gate' => '<path d="M4 20V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14"/><path d="M4 20h16M9 20V9h6v11"/>',
        ];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            .($paths[$key] ?? $paths['portfolio']).'</svg>';
    }
}
