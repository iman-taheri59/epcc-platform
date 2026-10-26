<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\InspectionNotificationService;
use App\Services\PdfRenderer;
use App\Services\ProjectArchiveService;
use App\Support\InspectionNotificationFilters;
use App\Support\InspectionNotificationInput;
use App\Support\InspectionNotificationPrerequisiteStage;
use App\Support\InspectionNotificationPrintData;
use App\Support\InspectionPrerequisiteFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class InspectionNotificationController extends Controller
{
    public function __construct(private readonly InspectionNotificationService $service) {}

    private function context(Request $request, string $code): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);

        return [Project::where('code', $code)->firstOrFail(), $request->user(), $lang];
    }

    private function payload(Request $request, string $key): array
    {
        $request->validate([$key => ['required', 'array']]);

        return $request->input($key);
    }

    public function index(Request $request, string $projectCode): View
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);

        return $this->service->read($project, $actor, function (User $fresh, ProjectMembership $member) use ($request, $project, $lang) {
            try {
                $filters = InspectionNotificationFilters::fromQuery($request->query());
            } catch (ValidationException $e) {
                if ($request->expectsJson()) {
                    throw $e;
                }
                // Read-only GET errors must not redirect to an arbitrary Referer
                // or silently apply empty filters and present misleading results.
                abort(422, __('inspection_notifications.filter_invalid'));
            }
            $selected = null;
            if ($request->filled('stage')) {
                $id = $request->query('stage');
                abort_unless(is_string($id) && Str::isUuid($id), 404);
                [$s, $rev, $doc] = $this->service->stage($project, $fresh, $member, $id);
                $selected = compact('s', 'rev', 'doc') + ['canDraft' => $s->requires_notification && $this->service->permission($project, $fresh, 'draft', $s->discipline) !== null];
            }

            return view('inspection-notifications.index', $this->service->dashboard($project, $fresh, $member, $filters) + compact('project', 'lang', 'selected'));
        });
    }

    public function prerequisiteRegister(Request $request, string $projectCode): View
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);

        return $this->service->read($project, $actor, function (User $fresh, ProjectMembership $member) use ($request, $project, $lang) {
            try {
                // Global TrimStrings can erase an all-NUL value before validation.
                // Parse the original GET query, then normalize only the allowlisted filters.
                parse_str((string) $request->server('QUERY_STRING', ''), $rawQuery);
                $filters = InspectionPrerequisiteFilters::fromQuery($rawQuery);
            } catch (ValidationException $e) {
                if ($request->expectsJson()) {
                    throw $e;
                }
                abort(422, __('inspection_notifications.filter_invalid'));
            }

            return view('inspection-notifications.prerequisite-register', $this->service->prerequisiteRegister($project, $member, $filters, $lang) + compact('project', 'lang'));
        });
    }

    public function configure(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $this->service->configure($project, $actor, $this->payload($request, 'settings'));

        return redirect()->route('inspection-notifications.index', ['projectCode' => $project->code, 'lang' => $lang])->with('status', __('inspection_notifications.saved'));
    }

    public function stage(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $id = $this->service->addStage($project, $actor, $this->payload($request, 'stage'));

        return redirect()->route('inspection-notifications.index', ['projectCode' => $project->code, 'lang' => $lang, 'stage' => $id])->with('status', __('inspection_notifications.saved'));
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $id = $this->service->createDraft($project, $actor, $this->payload($request, 'notification'));

        return redirect()->route('inspection-notifications.show', ['projectCode' => $project->code, 'notificationId' => $id, 'lang' => $lang]);
    }

    public function show(Request $request, string $projectCode, string $notificationId): View
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);

        return $this->service->read($project, $actor, function (User $fresh, ProjectMembership $member) use ($project, $lang, $notificationId) {
            $notice = $this->service->notification($project, $member, $notificationId);
            $cancellation = $this->service->cancellation($project, $member, $notice);
            $canCancel = $notice->state === 'ISSUED' && $cancellation === null && $notice->company_id === $fresh->company_id && $this->service->permission($project, $fresh, 'issue', $notice->discipline) !== null;
            $snapshot = $notice->issued_snapshot ? json_decode($notice->issued_snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
            $canIssue = $notice->state === 'DRAFT' && ($notice->activity_start_at ?? null) !== null && $notice->company_id === $fresh->company_id && $this->service->permission($project, $fresh, 'issue', $notice->discipline) !== null;

            $canEdit = $notice->state === 'DRAFT' && $notice->company_id === $fresh->company_id && $this->service->permission($project, $fresh, 'draft', $notice->discipline) !== null && app(ProjectArchiveService::class)->isWritable($project);

            return view('inspection-notifications.show', compact('project', 'lang', 'notice', 'snapshot', 'canIssue', 'canEdit', 'canCancel', 'cancellation') + $this->service->prerequisiteList($project, $fresh, $member, $notice, $cancellation) + $this->service->acknowledgmentContext($project, $fresh, $member, $notice, $cancellation) + $this->service->draftHistory($project, $fresh, $member, $notice));
        });
    }

    public function edit(Request $request, string $projectCode, string $notificationId): View
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);

        return $this->service->read($project, $actor, fn (User $fresh, ProjectMembership $member) => view('inspection-notifications.edit', $this->service->editContext($project, $fresh, $member, $notificationId) + ['project' => $project, 'lang' => $lang]));
    }

    public function update(Request $request, string $projectCode, string $notificationId): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $request->validate(InspectionNotificationInput::editRules());
        $this->service->updateDraft($project, $actor, $notificationId, $request->input('notification'));

        return redirect()->route('inspection-notifications.show', compact('projectCode', 'notificationId', 'lang'))->with('status', __('inspection_notifications.saved'));
    }

    /** Read-only export: same notice RLS, no live ITP/PO/person joins. */
    public function pdf(Request $request, string $projectCode, string $notificationId, PdfRenderer $renderer): Response
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        [$notice, $snapshot, $cancellation, $acknowledgments, $prerequisitePrint] = $this->service->read($project, $actor, function (User $fresh, ProjectMembership $member) use ($project, $notificationId): array {
            $notice = $this->service->notification($project, $member, $notificationId);

            return [$notice, InspectionNotificationPrintData::snapshot($notice), $this->service->cancellation($project, $member, $notice), $this->service->acknowledgments($project, $member, $notice), $this->service->prerequisitePrintContext($project, $member, $notice)];
        });
        $html = view('print.inspection-notification', compact('notice', 'snapshot', 'lang', 'cancellation', 'acknowledgments', 'prerequisitePrint') + ['prerequisiteStage' => InspectionNotificationPrerequisiteStage::fromNotice($notice), 'prerequisitePrintedAt' => now()->utc()->format('Y-m-d\TH:i:sP')])->render();
        try {
            $bytes = $renderer->render($html);
        } catch (\RuntimeException $e) {
            report($e);
            abort(503, __('inspection_notifications.pdf_unavailable'));
        }
        // Rendering can take time. Recheck current reader/recipient/entitlement
        // after it completes, rather than returning bytes to a departed party.
        $this->service->read($project, $actor, function (User $fresh, ProjectMembership $member) use ($project, $notificationId, $cancellation, $acknowledgments, $prerequisitePrint): void {
            $notice = $this->service->notification($project, $member, $notificationId);
            InspectionNotificationPrintData::snapshot($notice);
            abort_unless($this->service->cancellation($project, $member, $notice)?->id === $cancellation?->id, 409, __('inspection_notifications.pdf_lifecycle_changed'));
            abort_unless($this->service->acknowledgments($project, $member, $notice)->pluck('id')->all() === $acknowledgments->pluck('id')->all(), 409, __('inspection_notifications.pdf_acknowledgments_changed'));
            abort_unless($this->service->prerequisitePrintContext($project, $member, $notice)['fingerprint'] === $prerequisitePrint['fingerprint'], 409, __('inspection_notifications.pdf_prerequisites_changed'));
        });

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="inspection-notification-'.$notice->id.'-'.$lang.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function prerequisiteStore(Request $request, string $projectCode, string $notificationId): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $prerequisiteId = $this->service->createPrerequisite($project, $actor, $notificationId, $this->payload($request, 'prerequisite'));

        return redirect()->route('inspection-notifications.prerequisites.show', compact('projectCode', 'notificationId', 'prerequisiteId', 'lang'));
    }

    public function prerequisiteShow(Request $request, string $projectCode, string $notificationId, string $prerequisiteId): View
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);

        return $this->service->read($project, $actor, function (User $fresh, ProjectMembership $member) use ($project, $lang, $notificationId, $prerequisiteId) {
            $notice = $this->service->notification($project, $member, $notificationId);

            return view('inspection-notifications.prerequisite', $this->service->prerequisiteContext($project, $fresh, $member, $notice, $prerequisiteId) + compact('project', 'lang', 'notice'));
        });
    }

    public function prerequisiteUpdate(Request $request, string $projectCode, string $notificationId, string $prerequisiteId): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $this->service->updatePrerequisite($project, $actor, $notificationId, $prerequisiteId, $this->payload($request, 'prerequisite'));

        return redirect()->route('inspection-notifications.prerequisites.show', compact('projectCode', 'notificationId', 'prerequisiteId', 'lang'))->with('status', __('inspection_notifications.saved'));
    }

    public function acknowledge(Request $request, string $projectCode, string $notificationId): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $this->service->acknowledge($project, $actor, $notificationId, $this->payload($request, 'acknowledgment'));

        return redirect()->route('inspection-notifications.show', compact('projectCode', 'notificationId', 'lang'))->with('status', __('inspection_notifications.ack_saved'));
    }

    public function cancel(Request $request, string $projectCode, string $notificationId): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $this->service->cancel($project, $actor, $notificationId, $this->payload($request, 'cancellation'));

        return redirect()->route('inspection-notifications.show', compact('projectCode', 'notificationId', 'lang'))->with('status', __('inspection_notifications.cancelled'));
    }

    public function issue(Request $request, string $projectCode, string $notificationId): RedirectResponse
    {
        [$project, $actor, $lang] = $this->context($request, $projectCode);
        $this->service->issue($project, $actor, $notificationId, $this->payload($request, 'issuance'));

        return redirect()->route('inspection-notifications.show', ['projectCode' => $project->code, 'notificationId' => $notificationId, 'lang' => $lang])->with('status', __('inspection_notifications.issued'));
    }
}
