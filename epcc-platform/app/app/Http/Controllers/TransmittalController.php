<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use App\Models\Project;
use App\Models\Transmittal;
use App\Services\PdfRenderer;
use App\Services\TransmittalPackager;
use App\Services\TransmittalService;
use App\Support\AuthorizationPipeline;
use App\Support\CdeState;
use App\Support\TenantContext;
use App\Support\TransmittalPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Transmittal management (SRS FR-ENG-05).
 *
 * Same shape as the document register: bind the tenant, bind the project, and
 * let PostgreSQL RLS decide which letters exist. No hand-written company
 * filters - a filter written here is a filter that can be forgotten.
 */
final class TransmittalController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly TransmittalService $service,
        private readonly TransmittalPackager $packager,
        private readonly PdfRenderer $pdf,
    ) {
    }

    /** All transmittals visible to the acting company on this project. */
    /** The contractual stream: EPC -> Employer / Consultant. */
    public function index(Request $request, string $projectCode): View
    {
        return $this->register($request, $projectCode, Transmittal::CONTRACTOR);
    }

    /**
     * The vendor stream: supplier -> EPC, recorded by the EPC.
     *
     * A separate screen rather than a filter, because these are different
     * letters with different counterparties. Showing them together invites
     * exactly the mistake the separation exists to prevent: counting a
     * supplier's datasheet as a submission to the client.
     */
    public function vendorIndex(Request $request, string $projectCode): View
    {
        return $this->register($request, $projectCode, Transmittal::VENDOR);
    }

    private function register(Request $request, string $projectCode, string $kind): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.view', $project, $lang);

        [$transmittals, $documents, $companies, $vendors, $openContractorDrafts] = $this->tenant->runAs(
            $user->company_id,
            function () use ($user, $project, $kind): array {
                $transmittals = Transmittal::with([
                    'recipients.company', 'items.source.transmittal', 'sender', 'issuer', 'vendor',
                ])->where('kind', $kind)->orderByDesc('sequence')->get();

                // Only shareable documents may be attached, and only the
                // acting company's own: you transmit what you produced.
                $documents = Document::with('currentRevision')
                    ->where('company_id', $user->company_id)
                    ->get()
                    ->filter(fn (Document $d) => $d->currentRevision
                        && $d->currentRevision->cde_state !== CdeState::WIP)
                    ->values();

                $participantIds = \DB::table('project_company')
                    ->where('project_id', $project->id)
                    ->pluck('company_id')
                    ->push($project->owner_company_id)
                    ->unique()
                    ->reject(fn ($id) => $id === $user->company_id);

                $companies = Company::whereIn('id', $participantIds)->orderBy('code')->get();

                // Vendors that participate in this project. Used only by the
                // vendor screen, to name the originator of a letter.
                $vendors = Company::whereIn('id', $participantIds)
                    ->where('tenant_type', 'VEN')
                    ->orderBy('code')
                    ->get();

                // Contractor drafts this company can roll a vendor letter into.
                $openContractorDrafts = Transmittal::where('kind', Transmittal::CONTRACTOR)
                    ->where('company_id', $user->company_id)
                    ->where('status', Transmittal::DRAFT)
                    ->orderByDesc('sequence')
                    ->get();

                return [$transmittals, $documents, $companies, $vendors, $openContractorDrafts];
            },
            $project->id,
        );

        return view('transmittals.index', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'acting' => $user->company,
            'project' => $project,
            'kind' => $kind,
            'isVendorRegister' => $kind === Transmittal::VENDOR,
            'transmittals' => $transmittals,
            'documents' => $documents,
            'companies' => $companies,
            'vendors' => $vendors,
            'rollUpTargets' => $openContractorDrafts,
            'purposes' => TransmittalPurpose::all(),
            'canIssue' => $this->authz->allows($user, 'doc.issue', $project),
            'jalaliDate' => $this->today($lang),
        ]);
    }

    /** Create a draft, with its documents and recipients, in one submission. */
    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.issue', $project, $lang);

        $data = $request->validate([
            'subject_en' => ['required', 'string', 'max:512'],
            'subject_fa' => ['required', 'string', 'max:512'],
            'purpose' => ['required', 'string', 'in:'.implode(',', TransmittalPurpose::all())],
            'notes' => ['nullable', 'string', 'max:4000'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['uuid'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['uuid'],
            // Issuing straight away is the common case; drafting is for a
            // letter still being assembled.
            'issue_now' => ['nullable'],
            // Vendor stream. The service and a CHECK constraint both enforce
            // that these agree with the kind; this only shapes the input.
            'kind' => ['nullable', 'string', 'in:CONTRACTOR,VENDOR'],
            'vendor_company_id' => ['nullable', 'uuid'],
            'vendor_reference' => ['nullable', 'string', 'max:128'],
        ]);

        $this->tenant->runAs(
            $user->company_id,
            function () use ($project, $user, $data, $lang): void {
                $kind = $data['kind'] ?? Transmittal::CONTRACTOR;

                $vendor = ($kind === Transmittal::VENDOR && ! empty($data['vendor_company_id']))
                    ? Company::findOrFail($data['vendor_company_id'])
                    : null;

                $transmittal = $this->service->draft(
                    $project,
                    $user,
                    $data['subject_en'],
                    $data['subject_fa'],
                    $data['purpose'],
                    $data['notes'] ?? null,
                    $lang,
                    $kind,
                    $vendor,
                    $data['vendor_reference'] ?? null,
                );

                foreach ($data['documents'] as $documentId) {
                    // findOrFail inside the scope: RLS decides visibility, so a
                    // foreign id 404s naturally.
                    $document = Document::with('currentRevision')->findOrFail($documentId);
                    $this->service->addItem($transmittal, $document, $lang);
                }

                foreach ($data['recipients'] as $companyId) {
                    $this->service->addRecipient($transmittal, Company::findOrFail($companyId), $lang);
                }

                if ($request_issue = ($data['issue_now'] ?? null)) {
                    $this->service->issue($transmittal, $user, $lang);
                }
            },
            $project->id,
        );

        $back = ($data['kind'] ?? Transmittal::CONTRACTOR) === Transmittal::VENDOR
            ? "/projects/{$project->code}/vendor-transmittals"
            : "/projects/{$project->code}/transmittals";

        return redirect()
            ->to("{$back}?lang={$lang}")
            ->with('status', $lang === 'fa' ? 'نامه ثبت شد.' : 'Transmittal created.');
    }

    /** Roll an issued vendor letter's documents into a contractor draft. */
    public function rollUp(Request $request, string $projectCode, string $transmittalId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.issue', $project, $lang);

        $data = $request->validate([
            'target_id' => ['required', 'uuid'],
        ]);

        $count = $this->tenant->runAs(
            $user->company_id,
            function () use ($transmittalId, $data, $lang): int {
                $source = Transmittal::findOrFail($transmittalId);
                $target = Transmittal::findOrFail($data['target_id']);

                return count($this->service->rollUp($source, $target, $lang));
            },
            $project->id,
        );

        $message = $lang === 'fa'
            ? ($count > 0
                ? "{$count} مدرک به نامهٔ پیمانکار منتقل شد."
                : 'مدرک تازه‌ای برای انتقال نبود.')
            : ($count > 0
                ? "{$count} document(s) rolled up."
                : 'No new documents to roll up.');

        return redirect()
            ->to("/projects/{$project->code}/vendor-transmittals?lang={$lang}")
            ->with('status', $message);
    }

    /** Issue a draft, or acknowledge receipt of an issued one. */
    public function update(Request $request, string $projectCode, string $transmittalId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $action = $request->input('action');

        // Acknowledging is a RECIPIENT action and must not require the
        // sender's issuing capability - the whole point is that the other
        // party confirms receipt.
        $this->authorize(
            $request,
            $user,
            $action === 'acknowledge' ? 'doc.view' : 'doc.issue',
            $project,
            $lang,
        );

        $this->tenant->runAs(
            $user->company_id,
            function () use ($request, $transmittalId, $user, $action, $lang): void {
                $transmittal = Transmittal::with('recipients')->findOrFail($transmittalId);

                if ($action === 'acknowledge') {
                    $this->service->acknowledge($transmittal, $user, $request->input('note'), $lang);

                    return;
                }

                $this->service->issue($transmittal, $user, $lang);
            },
            $project->id,
        );

        return redirect()
            ->to("/projects/{$project->code}/transmittals?lang={$lang}")
            ->with('status', $action === 'acknowledge'
                ? ($lang === 'fa' ? 'دریافت تأیید شد.' : 'Receipt acknowledged.')
                : ($lang === 'fa' ? 'نامه صادر شد.' : 'Transmittal issued.'));
    }

    /** Bulk ZIP export with manifest (FR-ENG-05). */
    public function download(Request $request, string $projectCode, string $transmittalId): BinaryFileResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.view', $project, $lang);

        $transmittal = $this->tenant->runAs(
            $user->company_id,
            fn () => Transmittal::with(['items', 'recipients.company'])->findOrFail($transmittalId),
            $project->id,
        );

        // The blobs are on RLS tables too, so the packager runs INSIDE the
        // scope - outside it every file would silently read as missing.
        $path = $this->tenant->runAs(
            $user->company_id,
            fn () => $this->packager->build($transmittal, $lang),
            $project->id,
        );

        return response()
            ->download($path, $transmittal->number.'.zip', [
                'Content-Type' => 'application/zip',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }

    /** The transmittal sheet as a PDF (FR-ENG-05). */
    public function sheet(Request $request, string $projectCode, string $transmittalId): \Illuminate\Http\Response
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $this->authorize($request, $user, 'doc.view', $project, $lang);

        [$transmittal, $sender] = $this->tenant->runAs(
            $user->company_id,
            function () use ($transmittalId): array {
                $transmittal = Transmittal::with(['items', 'recipients.company'])->findOrFail($transmittalId);
                $sender = Company::find($transmittal->company_id);

                return [$transmittal, $sender];
            },
            $project->id,
        );

        $html = view('print.transmittal', [
            'lang' => $lang,
            'title' => $transmittal->number,
            'project' => $project,
            'transmittal' => $transmittal,
            'sender' => $sender,
            'generatedAt' => $this->today($lang),
        ])->render();

        try {
            $bytes = $this->pdf->render($html);
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        return new \Illuminate\Http\Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$transmittal->number.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || \DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function authorize(Request $request, $user, string $capability, Project $project, string $lang): void
    {
        $denial = $this->authz->check($user, $capability, $project);

        if ($denial !== null) {
            abort(403, $denial->message($lang));
        }
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'fa';
    }

    private function today(string $lang): string
    {
        $d = Jalalian::now()->format('Y/m/d');

        return $lang === 'fa' ? CalendarUtils::convertNumbers($d) : $d;
    }
}
