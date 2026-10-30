<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ItpSignOffService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ADR-048 A.4h slice 2 — ITP stage sign-offs get a surface.
 *
 * A notification says "we asked you to attend". Until A.4d nothing recorded
 * what happened at the stage, so a hold point nobody attended and one the
 * client's inspector attended and accepted looked identical in the data. The
 * service to record the difference has existed since A.4d; this is where a
 * person actually records it.
 *
 * Two refusals shape the screen rather than being left for the submit:
 * a form is offered only to someone nominated for `sign_off_stage`, and the
 * waiver outcome is offered on a HOLD point only to the project employer —
 * because a contractor waiving a HOLD is not a waiver, it is the removal of a
 * control the employer owns. Offering it and then refusing would be the
 * default-deny discovered on submit that slice 1 argued against.
 */
final class ItpSignOffController extends Controller
{
    public function __construct(
        private readonly ItpSignOffService $signOffs,
        private readonly TenantContext $tenant,
    ) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());
        $this->assertMember($project, $request->user());

        return [$project, $lang];
    }

    /**
     * The notices, the stages and the sign-offs all belong to the project, so
     * someone who is not on it has nothing to sign — and is told so before
     * anything is read, rather than being shown an empty list that looks like
     * a bug. The guards would refuse the write anyway.
     */
    private function assertMember(Project $project, User $actor): void
    {
        $member = $this->tenant->runAs($actor->company_id, fn (): bool => DB::table('project_memberships')
            ->where('project_id', $project->id)
            ->where('user_id', $actor->id)
            ->exists(), $project->id);

        abort_unless($member, 403);
    }

    public function show(Request $request, string $projectCode): View
    {
        [$project, $lang] = $this->context($request, $projectCode);

        $data = $this->signOffs->dashboard($project, $request->user(), $request->query('notice'), $lang);
        $notice = $data['notice'];

        // What this actor may record here, computed once so the view does not
        // have to reason about it: no nomination means no form, and a HOLD
        // waiver is the employer's alone.
        $outcomes = $data['outcomes'];
        if ($notice !== null && $notice->point === 'H' && ! $data['isEmployer']) {
            $outcomes = array_values(array_diff($outcomes, ['WAIVED_NOT_ATTENDED']));
        }

        // array_merge, not `+`: the union operator keeps the LEFT operand's
        // value for a key present in both, which would silently discard the
        // outcome filter above.
        return view('quality.sign-offs', array_merge($data, [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(),
            'acting' => $request->user()->company,
            'outcomes' => $data['nominated'] !== null ? $outcomes : [],
            'requestId' => (string) Str::uuid(),
        ]));
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $validated = $request->validate([
            'notification_id' => ['required', 'uuid'],
            'sign_off' => ['required', 'array'],
        ]);

        $signed = $this->signOffs->signOff(
            $project,
            $request->user(),
            (string) $validated['notification_id'],
            $request->input('sign_off'),
            $lang,
        );

        return redirect()->route('quality.sign-offs', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'notice' => $validated['notification_id'],
        ])->with('status', __('quality_signoffs.saved', ['point' => $signed['point']]));
    }
}
