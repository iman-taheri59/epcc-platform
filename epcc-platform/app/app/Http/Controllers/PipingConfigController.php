<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PipingLine;
use App\Models\Project;
use App\Services\PipingConfigService;
use App\Services\PipingLineService;
use App\Support\Numbering\TokenKind;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The line-numbering editor (ADR-006 §4).
 *
 * Separate from PipingLineController because the audience is different: that
 * screen is used daily by whoever is drawing lines, this one is touched once
 * at project setup by the employer's engineering lead. Mixing them would put a
 * project-wide destructive setting one tab away from routine data entry.
 */
final class PipingConfigController extends Controller
{
    public function __construct(
        private readonly PipingConfigService $config,
        private readonly PipingLineService $lines,
        private readonly TenantContext $tenant,
    ) {}

    public function edit(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$config, $vocab, $issued] = $this->tenant->runAs(
            $user->company_id,
            fn (): array => [
                $this->lines->configFor($project),
                $this->lines->vocabularies($project),
                (int) PipingLine::query()->where('project_id', $project->id)->max('sequence'),
            ],
            $project->id,
        );

        return view('piping.config', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'config' => $config,
            'pattern' => $config->pattern(),
            'vocab' => $vocab,
            'issued' => $issued,
            'tokenKinds' => TokenKind::all(),
            'registers' => PipingConfigService::registers(),
            'canEdit' => $user->company_id === $project->owner_company_id,
        ]);
    }

    public function updatePattern(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'separator' => ['required', 'string', 'max:3'],
            'tokens' => ['required', 'array', 'min:1'],
            'tokens.*.token' => ['required', 'string', 'max:40'],
            'tokens.*.source' => ['required', 'string'],
            'tokens.*.attribute' => ['nullable', 'string', 'max:60'],
            'tokens.*.width' => ['nullable', 'integer', 'min:0', 'max:9'],
            'tokens.*.optional' => ['nullable'],
            'tokens.*.values' => ['nullable', 'string'],
        ]);

        $tokens = [];

        foreach ($data['tokens'] as $row) {
            // A row the operator blanked out is a deletion, not an error.
            if (trim((string) $row['token']) === '') {
                continue;
            }

            $token = [
                'token' => trim((string) $row['token']),
                'source' => (string) $row['source'],
            ];

            if (($row['attribute'] ?? '') !== '') {
                $token['attribute'] = trim((string) $row['attribute']);
            }

            if ((int) ($row['width'] ?? 0) > 0) {
                $token['width'] = (int) $row['width'];
            }

            if (! empty($row['optional'])) {
                $token['optional'] = true;
            }

            // A literal carries its text in values[0]; the form collects it as
            // a single field rather than exposing the array shape.
            if (($row['values'] ?? '') !== '') {
                $token['values'] = array_values(array_filter(
                    array_map(trim(...), explode(',', (string) $row['values'])),
                    static fn (string $v): bool => $v !== '',
                ));
            }

            $tokens[] = $token;
        }

        $this->tenant->runAs(
            $user->company_id,
            fn () => $this->config->updatePattern($project, $user, [
                'separator' => $data['separator'],
                'tokens' => $tokens,
            ]),
            $project->id,
        );

        return redirect($this->backTo($projectCode, $lang, $request))
            ->with('status', __('epcc.line.config.saved'));
    }

    public function updateRegister(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'register' => ['required', 'string'],
            'values' => ['nullable', 'string'],
        ]);

        // One value per line is what an engineer pastes out of a PMS; commas
        // are accepted too rather than silently making one long value.
        $values = preg_split('/[\r\n,]+/', (string) ($data['values'] ?? '')) ?: [];

        $this->tenant->runAs(
            $user->company_id,
            fn () => $this->config->updateRegister($project, $user, $data['register'], $values),
            $project->id,
        );

        return redirect($this->backTo($projectCode, $lang, $request))
            ->with('status', __('epcc.line.config.saved'));
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

        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function backTo(string $projectCode, string $lang, Request $request): string
    {
        $query = ['lang' => $lang];

        if ($request->has('sid')) {
            $query['sid'] = $request->query('sid');
        }

        return "/projects/{$projectCode}/piping/config?".http_build_query($query);
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'fa';
    }
}
