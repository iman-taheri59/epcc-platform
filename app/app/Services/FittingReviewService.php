<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use App\Services\Engineering\MaterialScreeningEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Immutable configuration revisions, definition corrections and review events.
 * Approval is computed from ordered decisions, not a client-editable state.
 */
final class FittingReviewService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $definitions,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
        private readonly MaterialScreeningEvidence $evidence,
    ) {}

    private function scoped(Project $project, User $actor, callable $fn, bool $write = false): mixed
    {
        $this->definitions->assertAccess($project, $actor);
        if ($write) { $this->archives->assertWritable($project); }
        return $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $fn, $write) {
            // Append-only tables cannot use UPDATE-policy-dependent FOR UPDATE.
            // Serialize all workflow writes for a project on one advisory lock.
            if ($write) { DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['fitting-review:'.$project->id]); }
            return $fn();
        }), $project->id);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['review' => __('fitting_review.'.$key)]);
    }

    public function canConfigure(Project $project, User $actor): bool
    {
        if ($project->owner_company_id !== $actor->company_id) { return false; }
        foreach ($actor->rolesOn($project->id) as $role) {
            if ($role->grants('project.configure')) { return true; }
        }
        return false;
    }

    /** Only active members of participating companies; no global identity list. */
    private function people(Project $project): array
    {
        $members = ProjectMembership::where('project_id', $project->id)->get()->keyBy('user_id');
        $companies = DB::table('project_company')->where('project_id', $project->id)->pluck('company_id');
        return $this->tenant->forIdentityLookup(fn () => User::whereIn('id', $members->keys())
            ->whereIn('company_id', $companies)->where('is_active', true)->get(['id','name','company_id'])
            ->filter(fn ($u) => $members[$u->id]->discipline_scope === null
                || in_array('PIPING', $members[$u->id]->discipline_scope, true))
            ->map(fn ($u) => ['id'=>$u->id,'name'=>$u->name,'company_id'=>$u->company_id,
                'roles'=>$members[$u->id]->role_codes ?? []])->all());
    }

    private function options(Project $project): array
    {
        $out=[];
        $roles=Role::all()->keyBy('code');
        $companies=DB::table('companies')->pluck('code','id');
        foreach ($this->people($project) as $person) {
            $out['user:'.$person['id']]=$person['name'].' · '.$companies[$person['company_id']];
            foreach ($person['roles'] as $role) {
                if (! isset($roles[$role])) { continue; }
                $label=app()->getLocale()==='fa' ? $roles[$role]->name_fa : $roles[$role]->name_en;
                $out['role:'.$person['company_id'].':'.$role]=$label.' · '.$companies[$person['company_id']];
            }
        }
        return $out;
    }

    private function matches(array $person, string $selector): bool
    {
        $parts=explode(':',$selector);
        return ($parts[0]==='user' && ($parts[1]??'')===$person['id'])
            || ($parts[0]==='role' && ($parts[1]??'')===$person['company_id'] && in_array($parts[2]??'', $person['roles'],true));
    }

    private function latestPolicy(string $projectId, string $family = 'FITTING'): ?object
    {
        return DB::table('fitting_review_policies')->where('project_id',$projectId)->where('material_family',$family)->orderByDesc('version')->first();
    }

    public function configure(Project $project, User $actor, array $input): void
    {
        $this->scoped($project,$actor,function () use ($project,$actor,$input) {
            abort_unless($this->canConfigure($project,$actor),403);
            abort_unless($this->authz->check($actor,'project.configure',$project)===null,403);
            $data=Validator::make($input,['stages'=>['required','array','max:5'], 'stages.*'=>['nullable','string','max:160']])->validate();
            $family=ProjectMaterialSchema::family($input);
            $selectors=array_values(array_filter($data['stages'],fn ($s)=>is_string($s) && trim($s)!==''));
            if ($selectors===[] || count(array_unique($selectors))!==count($selectors)) { $this->fail('bad_stages'); }
            $options=$this->options($project); $steps=[];
            foreach ($selectors as $selector) {
                if (!isset($options[$selector])) { $this->fail('bad_stages'); }
                $steps[]=['selector'=>$selector,'label'=>$options[$selector]];
            }
            if (!$this->canStaff($steps,$this->people($project),[])) { $this->fail('no_reviewers'); }
            DB::table('fitting_review_policies')->insert($this->identity($project,$actor)+[
                'material_family'=>$family,'version'=>($this->latestPolicy($project->id,$family)->version??0)+1,'steps'=>json_encode($steps,JSON_THROW_ON_ERROR)]);
        },true);
    }

    private function identity(Project $project, User $actor): array
    {
        return ['id'=>(string)Str::uuid(),'project_id'=>$project->id,'company_id'=>$actor->company_id,
            'created_by'=>$actor->id,'created_at'=>now()];
    }

    private function find(Project $project, string $id): ProjectFittingDefinition
    {
        abort_unless(Str::isUuid($id),404);
        return ProjectFittingDefinition::where('project_id',$project->id)->whereKey($id)->firstOrFail();
    }

    private function authors(ProjectFittingDefinition $row): array
    {
        $root=$row->root_id??$row->id;
        return ProjectFittingDefinition::where('project_id',$row->project_id)
            ->where(fn ($q)=>$q->where('id',$root)->orWhere('root_id',$root))->pluck('created_by')->unique()->all();
    }

    /** Find a distinct person for each step, excluding authors and prior signers. */
    private function canStaff(array $steps, array $people, array $excluded): bool
    {
        if ($steps===[]) { return true; }
        $step=array_shift($steps);
        foreach ($people as $person) {
            if (!in_array($person['id'],$excluded,true) && $this->matches($person,$step['selector'])
                && $this->canStaff($steps,$people,[...$excluded,$person['id']])) { return true; }
        }
        return false;
    }

    private function status(ProjectFittingDefinition $row): array
    {
        $run=DB::table('fitting_review_runs')->where('definition_id',$row->id)->first();
        $decisions=$run ? DB::table('fitting_review_decisions')->where('run_id',$run->id)->orderBy('stage')->get()->all() : [];
        $policy=$run ? DB::table('fitting_review_policies')->where('id',$run->policy_id)->first() : null;
        $steps=$policy ? json_decode($policy->steps,true,512,JSON_THROW_ON_ERROR) : [];
        $state='UNREVIEWED';
        if ($run) {
            $state=count($decisions)===count($steps) ? 'APPROVED' : 'IN_REVIEW';
            foreach ($decisions as $decision) { if ($decision->decision==='RETURN') { $state='CHANGES_REQUESTED'; } }
        }
        $successor=ProjectFittingDefinition::where('revises_id',$row->id)->value('id');
        if ($successor) { $state='SUPERSEDED'; }
        $screeningSnapshot=$run && $row->material_family!=='FITTING' ? $this->evidence->forRun($run->id) : null;
        return compact('state','run','policy','steps','decisions','successor','screeningSnapshot');
    }

    public function submit(Project $project, User $actor, string $id): void
    {
        $this->scoped($project,$actor,function () use ($project,$actor,$id) {
            $row=$this->find($project,$id);
            abort_unless($row->company_id===$actor->company_id,403);
            if ($this->status($row)['state']!=='UNREVIEWED') { $this->fail('stale'); }
            $policy=$this->latestPolicy($project->id,$row->material_family);
            if (!$policy) { $this->fail('not_configured'); }
            $steps=json_decode($policy->steps,true,512,JSON_THROW_ON_ERROR);
            if (!$this->canStaff($steps,$this->people($project),$this->authors($row))) { $this->fail('no_reviewers'); }
            $run=$this->identity($project,$actor)+['definition_id'=>$row->id,'policy_id'=>$policy->id];
            DB::table('fitting_review_runs')->insert($run);
            $this->evidence->capture($row,$run);
        },true);
    }

    public function decide(Project $project, User $actor, string $id, array $input): void
    {
        $this->definitions->assertAccess($project,$actor);
        $data=Validator::make($input,['decision'=>['required',Rule::in(['APPROVE','RETURN'])],
            'comment'=>['required','string','max:4000'],'stage'=>['required','integer','min:1','max:5']])->validate();
        if (trim($data['comment'])==='') { $this->fail('comment_required'); }
        $this->scoped($project,$actor,function () use ($project,$actor,$id,$data) {
            $row=$this->find($project,$id); $status=$this->status($row);
            if ($status['state']!=='IN_REVIEW') { $this->fail('stale'); }
            $index=count($status['decisions']);
            if ((int)$data['stage']!==$index+1) { $this->fail('stale'); }
            $authors=$this->authors($row);
            $signed=array_map(fn ($d)=>$d->created_by,$status['decisions']);
            if (in_array($actor->id,[...$authors,...$signed],true)) { $this->fail('self_review'); }
            $people=$this->people($project);
            $person=current(array_filter($people,fn ($p)=>$p['id']===$actor->id));
            abort_unless($person && $this->matches($person,$status['steps'][$index]['selector']),403);
            if ($data['decision']==='APPROVE'
                && !$this->canStaff(array_slice($status['steps'],$index+1),$people,[...$authors,...$signed,$actor->id])) {
                $this->fail('no_reviewers');
            }
            DB::table('fitting_review_decisions')->insert($this->identity($project,$actor)+[
                'run_id'=>$status['run']->id,'stage'=>$index+1,'decision'=>$data['decision'],
                'comment'=>$data['comment'],'actor_name'=>$actor->name]);
        },true);
    }

    public function correct(Project $project, User $actor, string $id, array $input): ProjectFittingDefinition
    {
        $this->definitions->assertAccess($project,$actor);
        $data=$this->definitions->validateInput($input);
        $reason=Validator::make($input,['correction_reason'=>['required','string','max:4000']])->validate()['correction_reason'];
        if (trim($reason)==='') { $this->fail('reason_required'); }
        return $this->scoped($project,$actor,function () use ($project,$actor,$id,$data,$reason) {
            $old=$this->find($project,$id);
            abort_unless($old->company_id===$actor->company_id,403);
            if ($old->material_family !== $data['material_family']) {
                throw ValidationException::withMessages(['component_type'=>__('project_materials.family_locked')]);
            }
            if ($this->status($old)['successor']) { $this->fail('stale'); }
            if ($old->only(array_keys($data))==$data) { $this->fail('no_change'); }
            $row=new ProjectFittingDefinition($data);
            $row->project_id=$project->id; $row->company_id=$actor->company_id; $row->created_by=$actor->id;
            $row->provenance='PROJECT_SPEC'; $row->review_state='UNREVIEWED';
            $row->revises_id=$old->id; $row->root_id=$old->root_id??$old->id; $row->correction_reason=$reason;
            $row->save();
            return $row;
        },true);
    }

    public function dashboard(Project $project, User $actor, iterable $rows, string $family = 'FITTING'): array
    {
        return $this->scoped($project,$actor,function () use ($project,$actor,$rows,$family) {
            $latest=$this->latestPolicy($project->id,$family); $states=[];
            $people=$this->people($project);
            $person=current(array_filter($people,fn ($p)=>$p['id']===$actor->id));
            foreach ($rows as $row) {
                $s=$this->status($row); $index=count($s['decisions']);
                $excluded=[...$this->authors($row),...array_map(fn ($d)=>$d->created_by,$s['decisions'])];
                $s['can_decide']=$s['state']==='IN_REVIEW' && $person
                    && !in_array($actor->id,$excluded,true) && $this->matches($person,$s['steps'][$index]['selector']);
                $states[$row->id]=$s;
            }
            return ['reviewStates'=>$states, 'reviewPolicy'=>$latest,
                'reviewSteps'=>$latest?json_decode($latest->steps,true):[],
                'canConfigure'=>$this->canConfigure($project,$actor), 'reviewOptions'=>$this->options($project)];
        });
    }

    public function correctionSource(Project $project, User $actor, string $id): ProjectFittingDefinition
    {
        return $this->scoped($project,$actor,function () use ($project,$actor,$id) {
            $row=$this->find($project,$id); abort_unless($row->company_id===$actor->company_id,403);
            if ($this->status($row)['successor']) { $this->fail('stale'); }
            return $row;
        });
    }
}
