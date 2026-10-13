<?php

declare(strict_types=1);
namespace App\Services;
use App\Services\Engineering\MaterialScreening;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectMaterialSchema
{
    public const FAMILIES=['FITTING','PIPE','FLANGE','VALVE','GASKET','BOLTING'];
    public const FIELDS=MaterialScreening::FIELDS;
    public static function family(array $input): string
    {
        return Validator::make(['family'=>$input['family']??'FITTING'],['family'=>['required',Rule::in(self::FAMILIES)]])->validate()['family'];
    }
    public static function validate(array $input): array
    {
        $family=$input['component_type'];
        $rules=['component_type'=>['required',Rule::in(array_keys(self::FIELDS))]];
        foreach (['qualifier'=>80,'size_designation'=>128,'reference_standard'=>200,'material_spec'=>200,'material_grade'=>120,
            'declaration_reason'=>4000,'spec_number'=>120,'spec_revision'=>60,'spec_clause'=>120,'source_text'=>10000] as $field=>$max) {
            $rules[$field]=[$field==='material_grade'?'nullable':'required','string','max:'.$max];
        }
        $rules['properties']=['nullable','array:'.implode(',',self::FIELDS[$family])];
        foreach (self::FIELDS[$family] as $field) {
            $rules['properties.'.$field]=str_ends_with($field,'_mm')?['nullable','numeric','decimal:0,4','gt:0','lt:10000000000']:['nullable','string','max:200'];
        }
        foreach (['nps','dn','od_mm','dimension','dimension_mm'] as $field) { $rules[$field]=['prohibited']; }
        $attributes=[];
        foreach (array_keys($rules) as $key) {$attributes[$key]=__('project_materials.'.str_replace('properties.','',$key));}
        $data=Validator::make($input,$rules,[],$attributes)->validate();
        foreach (['qualifier','size_designation','reference_standard','material_spec','declaration_reason','spec_number','spec_revision','spec_clause','source_text'] as $field) {
            if (trim($data[$field])==='') {throw ValidationException::withMessages([$field=>__('project_fittings.source_required')]);}
        }
        if (preg_match('/\bB16\s*\.\s*9\b/i',$data['reference_standard'])) {
            throw ValidationException::withMessages(['reference_standard'=>__('project_materials.not_b169')]);
        }
        $data['properties']=array_filter($data['properties']??[],fn ($v)=>$v!==null && $v!=='');
        foreach (['nps','dn','od_mm','dimension','dimension_mm'] as $field) {unset($data[$field]);}
        $data['material_family']=$family;
        return $data;
    }
}
