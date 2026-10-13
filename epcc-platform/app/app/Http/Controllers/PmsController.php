<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\Project;
use App\Services\ManualValves;
use App\Services\PipingClassBody;
use App\Services\PipingClassHeader;
use App\Services\PmsBulkEntryService;
use App\Services\PmsEntryService;
use App\Services\PmsMeasurementService;
use App\Services\PmsValveEntryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PmsController extends Controller
{
    public function __construct(private PmsEntryService $service, private PipingClassHeader $header, private PipingClassBody $body, private PmsBulkEntryService $bulk, private PmsMeasurementService $measurements, private PmsValveEntryService $valves, private ManualValves $manualValves) {}

    private function context(Request $r, string $code): array
    {
        $lang = $r->input('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);

        return [Project::where('code', $code)->firstOrFail(), $r->user(), $lang];
    }

    public function index(Request $r, string $projectCode, ?string $classId = null)
    {
        [$project,$user,$lang] = $this->context($r, $projectCode);
        $r->validate(['item' => ['nullable', 'uuid'], 'ndt' => ['nullable', 'uuid']]);

        return $this->service->scoped($project, $user, function () use ($r, $project, $user, $lang, $classId) {
            $classes = PipingClass::where('project_id', $project->id)->orderBy('code')->get();
            $class = $classId ? $classes->firstWhere('id', $classId) : null;
            abort_if($classId && ! $class, 404);
            if ($class) {
                $class->load(['notes', 'ndtRequirements']);
            }
            $items = $class ? PipingClassItem::with('valveSpec')->where('project_id', $project->id)->where('piping_class_id', $class->id)->orderBy('component_type')->orderBy('dn')->paginate(20)->withQueryString() : null;
            $editing = $class && $r->filled('item') ? PipingClassItem::with('valveSpec')->where('project_id', $project->id)->where('piping_class_id', $class->id)->whereKey($r->query('item'))->firstOrFail() : null;
            $ndtEditing = $class && $r->filled('ndt') ? $class->ndtRequirements->firstWhere('id', $r->query('ndt')) : null;
            abort_if($r->filled('ndt') && ! $ndtEditing, 404);
            $classConflicts = $class ? $this->body->endPreparationConflicts($class) : [];
            $definitions = $class ? $this->service->definitions($project, $user) : [];
            $findings = [];
            $links = [];
            $geometry = [];
            foreach ($items ?? [] as $item) {
                $findings[$item->id] = array_merge($this->body->findingsForItem($item, $class->notes->pluck('number')->all()), $this->manualValves->findingsFor($item, $item->valveSpec));
                foreach ($classConflicts as $conflict) {
                    if ($conflict['item'] === $item->id) {
                        $findings[$item->id][] = $conflict;
                    }
                }
                $links[$item->id] = $this->service->linkedFindings($item, $definitions);
                if (in_array($item->component_type, ['ELBOW', 'CAP'], true)) {
                    $geometry[$item->id] = $this->body->fittingDimensionForItem($item);
                }
            }

            return view('piping.pms', ['project' => $project, 'user' => $user, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
                'ndtEditing' => $ndtEditing, 'classes' => $classes, 'class' => $class, 'items' => $items, 'editing' => $editing, 'catalog' => $this->service->catalog(), 'definitions' => $definitions,
                'findings' => $findings, 'links' => $links, 'geometry' => $geometry, 'headerFindings' => $class ? $this->header->findings($class) : [],
                'valveCatalog' => $this->valves->catalog(),
                'measurements' => $this->measurements->presentation($project, $user, $class, $lang),
                'canWrite' => $this->service->canWrite($project, $user, $class),
                'authorAccess' => $this->service->authorAccess($project, $user),
                'history' => $class ? DB::table('pms_entry_events')->where('piping_class_id', $class->id)->orderByDesc('created_at')->orderByDesc('version')->paginate(20, ['*'], 'history_page')->withQueryString() : null]);
        });
    }

    public function access(Request $r, string $projectCode)
    {
        [$p,$a,$lang] = $this->context($r, $projectCode);
        $v = $r->validate(['access' => ['required', 'array']]);
        $this->service->configureAuthors($p, $a, $v['access']);

        return redirect()->route('pms', ['projectCode' => $p->code, 'lang' => $lang])->with('status', __('pms.access_saved'));
    }

    public function header(Request $r, string $projectCode, ?string $classId = null)
    {
        [$p,$a,$lang] = $this->context($r, $projectCode);
        $v = $r->validate(['header' => ['required', 'array']]);
        $c = $this->service->saveHeader($p, $a, $v['header'], $classId);

        return $this->back($r, $p->code, $c->id, $lang);
    }

    public function item(Request $r, string $projectCode, string $classId, ?string $itemId = null)
    {
        [$p,$a,$lang] = $this->context($r, $projectCode);
        $v = $r->validate(['item' => ['required', 'array']]);
        $this->service->saveItem($p, $a, $classId, $v['item'], $itemId);

        return $this->back($r, $p->code, $classId, $lang);
    }

    public function bulkPreview(Request $r, string $projectCode, string $classId)
    {
        [$p, $a, $lang] = $this->context($r, $projectCode);
        $v = $r->validate(['item' => ['required', 'array'], 'sizes' => ['required', 'array', 'max:100']]);
        $plan = $this->bulk->preview($p, $a, $classId, $v['item'], $v['sizes']);
        $r->session()->flashInput(['item' => array_replace($v['item'], ['target' => 'new']), 'sizes' => $v['sizes']]);

        return response()->view('piping.pms-bulk-preview', [
            'project' => $p, 'user' => $a, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'plan' => $plan,
        ])->header('Cache-Control', 'no-store');
    }

    public function bulkConfirm(Request $r, string $projectCode, string $classId)
    {
        [$p, $a, $lang] = $this->context($r, $projectCode);
        try {
            $v = $r->validate(['preview_token' => ['required', 'string', 'max:200000']]);
            $count = $this->bulk->confirm($p, $a, $classId, $v['preview_token']);
        } catch (ValidationException $e) {
            if ($r->expectsJson()) {
                throw $e;
            }

            return redirect()->route('pms.show', ['projectCode' => $p->code, 'classId' => $classId, 'lang' => $lang])
                ->withErrors($e->errors())->withInput($r->session()->getOldInput());
        }

        return $this->back($r, $p->code, $classId, $lang)->with('status', __('pms.bulk_saved', ['count' => $count]));
    }

    public function ndt(Request $r, string $projectCode, string $classId)
    {
        [$p,$a,$lang] = $this->context($r, $projectCode);
        $v = $r->validate(['ndt' => ['required', 'array']]);
        $this->service->saveNdt($p, $a, $classId, $v['ndt']);

        return $this->back($r, $p->code, $classId, $lang);
    }

    public function note(Request $r, string $projectCode, string $classId)
    {
        [$p,$a,$lang] = $this->context($r, $projectCode);
        $v = $r->validate(['note' => ['required', 'array']]);
        $this->service->saveNote($p, $a, $classId, $v['note']);

        return $this->back($r, $p->code, $classId, $lang);
    }

    private function back(Request $r, string $code, string $id, string $lang)
    {
        return redirect('/projects/'.rawurlencode($code).'/piping/pms/'.$id.'?'.http_build_query(['lang' => $lang] + ($r->query('sid') ? ['sid' => $r->query('sid')] : [])))->with('status', __('pms.saved'));
    }
}
