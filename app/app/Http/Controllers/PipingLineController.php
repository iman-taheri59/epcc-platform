<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\LineDocument;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Services\PipingLineService;
use App\Support\AuthorizationPipeline;
use App\Support\LineReleaseState;
use App\Support\Numbering\Exceptions\UnknownTokenValueException;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * The piping line register and its AFC gate (ADR-006 phase 1).
 *
 * Reads are open to every project participant - the contractor needs the line
 * list as much as the employer does. Writes go through PipingLineService,
 * which owns the numbering and the gate rules; this class only resolves who is
 * asking and about what.
 */
final class PipingLineController extends Controller
{
    public function __construct(
        private readonly PipingLineService $lines,
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
    ) {}

    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$rows, $subsystems, $classes, $stats] = $this->tenant->runAs(
            $user->company_id,
            function () use ($project): array {
                $lines = PipingLine::with(['subsystem', 'pipingClass'])
                    ->orderBy('line_number')
                    ->get();

                $stats = [
                    'total' => $lines->count(),
                    'draft' => $lines->where('release_state', LineReleaseState::DRAFT)->count(),
                    'afc' => $lines->where('release_state', LineReleaseState::AFC_CONFIRMED)->count(),
                    'released' => $lines->where('release_state', LineReleaseState::RELEASED)->count(),
                    'external' => $lines->whereNull('sequence')->count(),
                ];

                return [
                    $lines,
                    Subsystem::orderBy('code')->get(),
                    PipingClass::orderBy('code')->get(),
                    $stats,
                ];
            },
            $project->id,
        );

        // Evidence readiness for every DRAFT line, so the register can show
        // WHY a line cannot pass the gate rather than only that it has not.
        $blockers = $this->tenant->runAs(
            $user->company_id,
            function () use ($rows): array {
                $out = [];
                foreach ($rows as $line) {
                    if ($line->release_state !== LineReleaseState::DRAFT) {
                        continue;
                    }
                    $out[$line->id] = [
                        'missing' => $this->lines->missingEvidence($line),
                        'unapproved' => $this->lines->unapprovedEvidence($line),
                    ];
                }

                return $out;
            },
            $project->id,
        );

        $vocab = $this->tenant->runAs(
            $user->company_id,
            fn (): array => $this->lines->vocabularies($project),
            $project->id,
        );

        return view('piping.index', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'rows' => $rows,
            'subsystems' => $subsystems,
            'classes' => $classes,
            'stats' => $stats,
            'blockers' => $blockers,
            'pattern' => $this->lines->configFor($project)->pattern()->sketch(),
            'vocab' => $vocab,
            'patternTokens' => $this->lines->configFor($project)->pattern()->tokenNames(),
            'jalaliDate' => $this->today($lang),
        ]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'subsystem_id' => ['required', 'uuid'],
            'nominal_size' => ['nullable', 'string', 'max:16'],
            'service_code' => ['nullable', 'string', 'max:16'],
            'piping_class_id' => ['nullable', 'uuid'],
            'coating_code' => ['nullable', 'string', 'max:16'],
            'line_number' => ['nullable', 'string', 'max:128'],
            'from_ref' => ['nullable', 'string', 'max:255'],
            'to_ref' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($data, $user, $project): void {
                $subsystem = Subsystem::findOrFail($data['subsystem_id']);

                // Two doors, deliberately. A supplied number is registered
                // as-is (ADR-006 s10.2 forbids inventing or normalising
                // numbers); an empty field means "allocate one from the
                // project pattern".
                if (($data['line_number'] ?? '') !== '') {
                    $this->lines->register($subsystem, $user, (string) $data['line_number'], $data);
                } else {
                    $this->lines->create($subsystem, $user, $data);
                }
            }, $project->id);
        } catch (UnknownTokenValueException $e) {
            // The engine is right to refuse: a value outside the project
            // register would put an unrecognisable number on a drawing. But
            // that is the operator picking a value the project has not set up
            // yet, which is a form error to correct - not a crash.
            // Not back(): a form POST carries no referer in every client, and
            // back() would then land on / and burn the flashed error there.
            return redirect($this->backTo($projectCode, $lang, $request))
                ->withInput()
                ->withErrors(['line_number' => $e->getMessage()]);
        }

        return redirect($this->backTo($projectCode, $lang, $request));
    }

    /** Confirm AFC, release, or pull a line back to draft. */
    public function transition(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'to' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $user, $lineId): void {
            $line = PipingLine::findOrFail($lineId);
            $note = $data['note'] ?? null;

            match ($data['to']) {
                LineReleaseState::AFC_CONFIRMED => $this->lines->confirmAfc($line, $user, $note),
                LineReleaseState::RELEASED => $this->lines->release($line, $user, $note),
                LineReleaseState::DRAFT => $this->lines->revertToDraft($line, $user, (string) $note),
                default => abort(422),
            };
        }, $project->id);

        return redirect($this->backTo($projectCode, $lang, $request));
    }

    /** Attach a CDE document as AFC evidence. */
    public function attach(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'document_id' => ['required', 'uuid'],
            'evidence_kind' => ['required', 'string'],
        ]);

        $this->tenant->runAs($user->company_id, function () use ($data, $user, $lineId): void {
            $line = PipingLine::findOrFail($lineId);
            $document = Document::findOrFail($data['document_id']);

            $this->lines->attachEvidence($line, $user, $document, (string) $data['evidence_kind']);
        }, $project->id);

        return redirect($this->backTo($projectCode, $lang, $request));
    }

    // ---------------------------------------------------------- internals --

    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        // Company participation AND personal membership, the same two
        // conditions the certificate and punch registers enforce.
        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function backTo(string $projectCode, string $lang, Request $request): string
    {
        $query = ['lang' => $lang];

        if ($request->has('sid')) {
            $query['sid'] = $request->query('sid');
        }

        return "/projects/{$projectCode}/piping?".http_build_query($query);
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
