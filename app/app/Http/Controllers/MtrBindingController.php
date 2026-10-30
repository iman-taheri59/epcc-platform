<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\MaterialCertificateBindingService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ADR-048 A.4h slice 3 — the MTR registry and its bindings get a surface.
 *
 * Two things were true before this screen and are the reason it holds both
 * forms. A material certificate could only be registered by SQL, so the
 * registry — the thing the whole binding machinery exists to connect to
 * custody — had no way in. And once registered, a certificate could only be
 * bound by SQL too. Authority that cannot be exercised is paperwork about
 * authority, so this screen closes both ends of the same loop: register the
 * certificate you were given, then bind it to the custody that attests its
 * heat.
 *
 * The rule from the earlier slices still holds — a form is offered only where
 * the register says this actor is nominated for that action — and there are
 * two actions here, which are two different authorities: registering a
 * certificate is a claim about a document; binding one to custody is a claim
 * about material we hold. One does not confer the other.
 */
final class MtrBindingController extends Controller
{
    public function __construct(
        private readonly MaterialCertificateBindingService $bindings,
        private readonly InspectionResultService $results,
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

    /** Certificates and custody both belong to the project; a non-member stops here. */
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

        $data = $this->bindings->dashboard($project, $request->user(), $request->query('certificate'), $lang);

        return view('quality.mtr-bindings', array_merge($data, [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(),
            'acting' => $request->user()->company,
            'requestId' => (string) Str::uuid(),
        ]));
    }

    /** Registers a certificate the project was given — a claim about a document. */
    public function storeCertificate(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $request->validate(['certificate' => ['required', 'array']]);

        $id = $this->results->registerCertificate(
            $project,
            $request->user(),
            $request->input('certificate'),
            $lang,
        );

        return redirect()->route('quality.mtr', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'certificate' => $id,
        ])->with('status', __('quality_mtr.certificate_saved'));
    }

    /** Binds a certificate to the custody that attests its heat — a claim about material. */
    public function storeBinding(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $validated = $request->validate([
            'certificate_id' => ['required', 'uuid'],
            'binding' => ['required', 'array'],
        ]);

        $bound = $this->bindings->bindCertificate(
            $project,
            $request->user(),
            (string) $validated['certificate_id'],
            $request->input('binding'),
            $lang,
        );

        return redirect()->route('quality.mtr', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'certificate' => $validated['certificate_id'],
        ])->with('status', __('quality_mtr.saved', ['binding' => $bound['binding']]));
    }
}
