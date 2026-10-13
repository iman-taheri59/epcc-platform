<?php

declare(strict_types=1);
namespace App\Services\Engineering;

use App\Models\ProjectFittingDefinition;
use Illuminate\Support\Facades\DB;

/** Called inside the review service's authorized tenant transaction. No public write endpoint. */
final class MaterialScreeningEvidence
{
    private readonly MaterialScreening $checker;
    public function __construct(?string $referenceDirectory=null)
    {
        $this->checker=new MaterialScreening($referenceDirectory??base_path('../standards-packs/reference'));
    }
    public function input(ProjectFittingDefinition $row): array
    {
        return $row->only(['component_type','qualifier','size_designation','reference_standard','material_spec',
            'material_grade','declaration_reason','spec_number','spec_revision','spec_clause','source_text','properties']);
    }
    public function current(ProjectFittingDefinition $row): array
    {
        return $this->checker->check($this->input($row));
    }
    public function capture(ProjectFittingDefinition $row,array $run): void
    {
        if ($row->material_family==='FITTING') {return;}
        $input=$this->input($row);
        $inputJson=json_encode($input,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
        $resultJson=json_encode($this->checker->check($input),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
        $implementation=hash_file('sha256',__DIR__.'/MaterialScreening.php');
        if ($implementation===false) {throw new \RuntimeException('Screening implementation fingerprint unavailable.');}
        DB::table('material_screening_snapshots')->insert([
            'run_id'=>$run['id'],'project_id'=>$row->project_id,'company_id'=>$row->company_id,'definition_id'=>$row->id,
            'created_by'=>$run['created_by'],'created_at'=>$run['created_at'],'input_json'=>$inputJson,
            'result_json'=>$resultJson,'result_sha256'=>hash('sha256',$resultJson),'implementation_sha256'=>$implementation,
        ]);
    }
    public function forRun(string $runId): ?array
    {
        $row=DB::table('material_screening_snapshots')->where('run_id',$runId)->first();
        if (!$row) {return null;}
        return ['record'=>$row,'input'=>json_decode($row->input_json,true,512,JSON_THROW_ON_ERROR),
            'result'=>json_decode($row->result_json,true,512,JSON_THROW_ON_ERROR)];
    }
}
