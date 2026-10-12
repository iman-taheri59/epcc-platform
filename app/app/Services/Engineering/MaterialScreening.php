<?php

declare(strict_types=1);
namespace App\Services\Engineering;

/** Read-only declaration screening. Never certifies a standard, edits a source or grants approval. */
final class MaterialScreening
{
    public const VERSION = '1.0.0';
    public const FIELDS = [
        'PIPE'=>['schedule','wall_thickness_mm','outside_diameter_mm','product_form'],
        'FLANGE'=>['pressure_rating','facing','series','bore_mm','outside_diameter_mm','thickness_mm'],
        'VALVE'=>['construction_standard','pressure_rating','end_connection','trim','operator','face_to_face_mm'],
        'GASKET'=>['gasket_type','pressure_rating','ring_number','inside_diameter_mm','outside_diameter_mm','thickness_mm'],
        'BOLTING'=>['thread_standard','length_mm','nut_height_mm','coating'],
    ];
    public function __construct(private readonly string $referenceDirectory) {}

    public function check(array $declaration): array
    {
        $issues=[]; $references=[];
        $add=static function (string $code,string $severity,string $field,string $en,string $fa, string $basis='SCREENING_PROFILE') use (&$issues): void {
            $issues[]=compact('code','severity','field','basis')+['message'=>['en'=>$en,'fa'=>$fa]];
        };
        $missing=fn ($v)=>!is_string($v) || trim($v)==='';
        $family=$declaration['component_type']??null;
        if (!is_string($family) || !isset(self::FIELDS[$family])) {
            $add('FAMILY_NOT_SUPPORTED','INCOMPLETE','component_type','Select a supported non-fitting material family. Fitting checks remain separate.','یکی از گروه‌های غیر فیتینگ پشتیبانی‌شده را انتخاب کنید؛ کنترل فیتینگ جداست.');
        } else {
            foreach (['qualifier','size_designation','reference_standard','material_spec','declaration_reason','spec_number','spec_revision','spec_clause','source_text'] as $field) {
                if ($missing($declaration[$field]??null)) {
                    $add('SOURCE_FIELD_REQUIRED','INCOMPLETE',$field,'Source declaration field is missing or not text.','اطلاعات منبع خالی است یا به صورت متن ثبت نشده است.');
                }
            }
            $p=$declaration['properties']??[];
            if (!is_array($p) || ($p!==[] && array_is_list($p))) {
                $add('PROPERTY_OBJECT_REQUIRED','DISCREPANCY','properties','Technical attributes must be a named object.','ویژگی‌های فنی باید مجموعه‌ای از کلید و مقدار باشند.');
                $p=[];
            }
            $numbers=[];
            foreach ($p as $field=>$value) {
                if (!in_array($field,self::FIELDS[$family],true)) {
                    $add('PROPERTY_NOT_SUPPORTED','REVIEW_REQUIRED','properties.'.$field,'This property is not checked for the selected family; do not assume compliance.','این ویژگی برای گروه انتخاب‌شده کنترل نمی‌شود؛ انطباق فرض نشود.');
                    continue;
                }
                if ($value===null || $value==='') { continue; }
                if (str_ends_with($field,'_mm')) {
                    if ((!is_string($value) && !is_int($value) && !is_float($value))
                        || !preg_match('/^\d+(?:\.\d{1,4})?$/D',(string)$value)
                        || !is_finite((float)$value) || (float)$value<=0 || (float)$value>=10000000000) {
                        $add('DIMENSION_INVALID','DISCREPANCY','properties.'.$field,'Declared mm dimension must be positive, finite and have at most four decimals.','مقدار اظهارشده بر حسب میلی‌متر باید مثبت، متناهی و حداکثر چهار رقم اعشار داشته باشد.');
                    } else { $numbers[$field]=(float)$value; }
                } elseif ($missing($value)) {
                    $add('TEXT_ATTRIBUTE_INVALID','INCOMPLETE','properties.'.$field,'Use the source wording, not an array, number or blank value.','متن منبع را ثبت کنید؛ آرایه، عدد بدون شرح یا متن خالی کافی نیست.');
                }
            }
            // Minimum review preparation, NOT universal mandatory clauses of a standard.
            $required=match ($family) {
                'PIPE'=>['product_form'], 'FLANGE'=>['pressure_rating','facing'],
                'VALVE'=>['construction_standard','pressure_rating','end_connection','trim','operator'],
                'GASKET'=>['gasket_type'], 'BOLTING'=>['thread_standard'],
            };
            foreach ($required as $field) {
                if ($missing($p[$field]??null)) {
                    $add('TECHNICAL_CONTEXT_MISSING','INCOMPLETE','properties.'.$field,'Minimum screening context is absent; verify applicability against the project Spec. This is not a quoted standard clause.','اطلاعات پایه برای غربالگری ناقص است؛ کاربردپذیری را با Spec بررسی کنید. این شرط نقل یک بند استاندارد نیست.');
                }
            }
            if ($family==='PIPE' && $missing($p['schedule']??null) && !isset($numbers['wall_thickness_mm'])) {
                $add('PIPE_WALL_BASIS_MISSING','INCOMPLETE','properties.schedule','Declare the source schedule or wall thickness; neither is inferred.','اسکجول یا ضخامت دیوارهٔ منبع را ثبت کنید؛ هیچ‌کدام استنتاج نمی‌شود.');
            }
            $standard=is_string($declaration['reference_standard']??null)?$declaration['reference_standard']:'';
            if (preg_match('/\bB16\s*\.\s*9\b/i',$standard)) {
                $add('FITTING_STANDARD_MISAPPLIED','DISCREPANCY','reference_standard','B16.9 is not the component standard for this non-fitting family.','B16.9 استاندارد قطعه برای این گروه غیر فیتینگ نیست.','B16.9 component scope; no size inference');
            }
            if ($family==='FLANGE' && preg_match('/\bB16\s*\.\s*47\b/i',$standard)
                && !in_array(strtoupper(trim(is_string($p['series']??null)?$p['series']:'')),['A','B'],true)) {
                $add('FLANGE_SERIES_REQUIRED','INCOMPLETE','properties.series','Identify B16.47 Series A or B from the cited source; do not select a default.','سری A یا B فلنج B16.47 را از منبع تعیین کنید؛ سری پیش‌فرض انتخاب نمی‌شود.');
            }
            $rating=$p['pressure_rating']??null;
            if (is_string($rating) && trim($rating)!=='' && !preg_match('/^(?:Class\s*\d+|PN\s*\d+(?:\.\d+)?|\d+(?:\.\d+)?\s*(?:psi|bar|MPa|kPa))$/i',trim($rating))) {
                $add('RATING_BASIS_UNRESOLVED','REVIEW_REQUIRED','properties.pressure_rating','Rating basis/units are unresolved. Class and PN are not converted or treated as equivalent.','مبنای رده فشار یا واحد روشن نیست؛ Class و PN تبدیل یا معادل فرض نمی‌شوند.');
            }
            if ($family==='BOLTING') {
                $subtype=strtoupper(trim(is_string($declaration['qualifier']??null)?$declaration['qualifier']:''));
                if (in_array($subtype,['BOLT','STUD','STUD_BOLT'],true) && !isset($numbers['length_mm'])) {
                    $add('BOLT_LENGTH_REQUIRED','INCOMPLETE','properties.length_mm','Declare bolt/stud length from the source.','طول پیچ یا استادبولت را از منبع ثبت کنید.');
                } elseif (!in_array($subtype,['BOLT','STUD','STUD_BOLT','NUT'],true)) {
                    $add('BOLTING_SUBTYPE_REVIEW','REVIEW_REQUIRED','qualifier','Subtype is not recognized for conditional length checks; do not infer bolt versus nut.','زیرنوع برای کنترل شرطی طول شناخته نشده است؛ پیچ یا مهره بودن حدس زده نمی‌شود.');
                }
                if (is_string($declaration['size_designation']??null) && preg_match('/^\s*(NPS|DN)\b/i',$declaration['size_designation'])) {
                    $add('THREAD_DESIGNATION_REQUIRED','INCOMPLETE','size_designation','Pipe nominal size is not a bolt thread designation.','سایز اسمی لوله، نام‌گذاری رزوهٔ پیچ نیست.');
                }
            }
            if (isset($numbers['outside_diameter_mm'])) {
                $od=$numbers['outside_diameter_mm'];
                foreach (['inside_diameter_mm','bore_mm'] as $inner) {
                    if (isset($numbers[$inner]) && $numbers[$inner]>=$od) {
                        $add('IMPOSSIBLE_BORE','DISCREPANCY','properties.'.$inner,'Declared internal diameter is not smaller than external diameter.','قطر داخلی اظهارشده از قطر خارجی کوچک‌تر نیست.','Declared geometry, same units');
                    }
                }
                if ($family==='PIPE' && isset($numbers['wall_thickness_mm']) && 2*$numbers['wall_thickness_mm']>=$od) {
                    $add('PIPE_BORE_CLOSED','DISCREPANCY','properties.wall_thickness_mm','Declared OD minus twice the wall thickness leaves no open pipe bore.','قطر خارجی منهای دو برابر ضخامت دیواره، مجرای باز برای لوله باقی نمی‌گذارد.','Declared geometry, same units');
                }
            }
            if ($family==='PIPE') { $this->pipeComparison($declaration,$p,$numbers,$add,$references); }
        }
        $add('SOURCE_REVIEW_REQUIRED','REVIEW_REQUIRED','source_text','Verify the actual Spec, standard edition, applicability and completeness. Automated screening is not engineering approval.','Spec واقعی، ویرایش استاندارد، کاربردپذیری و کامل‌بودن اطلاعات باید بررسی شوند؛ غربالگری خودکار تأیید مهندسی نیست.','Workflow boundary');
        $severities=array_column($issues,'severity');
        return ['ruleset'=>'material-screening/'.self::VERSION,
            'input_sha256'=>hash('sha256',json_encode($declaration,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)),
            'status'=>in_array('DISCREPANCY',$severities,true)?'DISCREPANCY':(in_array('INCOMPLETE',$severities,true)?'INCOMPLETE':'REVIEW_REQUIRED'),
            'engineering_approved'=>false,'automatic_consumption_allowed'=>false,
            'findings'=>$issues,'references'=>$references];
    }

    private function pipeComparison(array $d,array $p,array $numbers,callable $add,array &$references): void
    {
        $standard=is_string($d['reference_standard']??null)?preg_replace('/\s+/',' ',strtoupper(trim($d['reference_standard']))):'';
        if (!in_array($standard,['ASME B36.10M-2022','ASME B36.19M-2018'],true)) {
            $add('REFERENCE_EDITION_NOT_MATCHED','REVIEW_REQUIRED','reference_standard','No exact supported pipe standard/edition match; no dimensional comparison was made.','استاندارد و ویرایش لوله دقیقاً با مرجع موجود منطبق نیست؛ مقایسهٔ ابعادی انجام نشد.'); return;
        }
        try {
            $packs=[];
            foreach (['pipe-sizes','pipe-schedules'] as $id) {
                $path=$this->referenceDirectory.'/'.$id.'.ref.json';
                if (!is_readable($path)) { throw new \RuntimeException('Reference unavailable'); }
                $raw=file_get_contents($path); $pack=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($pack) || ($pack['refId']??null)!==$id || !isset($pack['source'],$pack['version'])
                    || !str_contains($pack['source'],substr($standard,5))) { throw new \RuntimeException('Reference metadata mismatch'); }
                $packs[$id]=$pack;
                $references[]=['ref_id'=>$id,'version'=>$pack['version'],'source'=>$pack['source'],'sha256'=>hash('sha256',$raw)];
            }
            if (!is_array($packs['pipe-sizes']['sizes']??null) || !is_array($packs['pipe-schedules']['walls']??null)) {
                throw new \RuntimeException('Reference shape mismatch');
            }
        } catch (\Throwable) {
            $add('REFERENCE_UNAVAILABLE','REVIEW_REQUIRED','reference_standard','Reference is missing, malformed or has incompatible metadata; comparison was not completed.','مرجع موجود نیست، ساختارش خراب است یا مشخصات سازگار ندارد؛ مقایسه تکمیل نشد.'); return;
        }
        $designation=$d['size_designation']??''; $size=null;
        if (is_string($designation) && preg_match('/^NPS\s+(.+?)\s+(?:in|inch|")$/i',trim($designation),$m)) {
            foreach ($packs['pipe-sizes']['sizes'] as $candidate) {
                if (($candidate['nps']??null)===$m[1]) { $size=$candidate; break; }
            }
        }
        if (!$size || !is_numeric($size['odMm']??null) || (float)$size['odMm']<=0) {
            $add('REFERENCE_SIZE_NOT_RESOLVED','REVIEW_REQUIRED','size_designation','Explicit size was not resolved in this loaded pack. This does not establish an outside-standard size.','سایز صریح در بستهٔ موجود تعیین نشد؛ این به معنی خارج‌بودن از محدوده استاندارد نیست.'); return;
        }
        $compare=static function (string $field,float $expected,string $basis) use ($numbers,$add): void {
            if (isset($numbers[$field]) && abs($numbers[$field]-$expected)>0.00005) {
                $add('REFERENCE_VALUE_DIFFERENCE','REVIEW_REQUIRED','properties.'.$field,
                    'Declared value differs from loaded nominal reference ('.$expected.' mm). Check the source and tolerances; do not overwrite it.',
                    'مقدار اظهارشده با مرجع اسمی موجود ('.$expected.' میلی‌متر) متفاوت است؛ منبع و تلرانس بررسی شود؛ مقدار جایگزین نشود.',$basis);
            }
        };
        $compare('outside_diameter_mm',(float)$size['odMm'],'pipe-sizes '.$packs['pipe-sizes']['version'].'; NPS '.$size['nps']);
        if (!is_string($p['schedule']??null) || trim($p['schedule'])==='') { return; }
        $schedule=strtoupper(trim($p['schedule']));
        // The combined pack does not declare per-cell standard applicability for aliases.
        if (($standard==='ASME B36.19M-2018' && !in_array($schedule,['5S','10S','40S','80S'],true))
            || ($standard==='ASME B36.10M-2022' && str_ends_with($schedule,'S') && !in_array($schedule,['XS','XXS'],true))) {
            $add('SCHEDULE_STANDARD_UNRESOLVED','REVIEW_REQUIRED','properties.schedule','Schedule applicability to the selected standard needs source review; no alias conversion was used.','کاربرد اسکجول برای استاندارد انتخاب‌شده نیازمند بررسی منبع است؛ نام‌ها تبدیل نشدند.'); return;
        }
        $wall=$packs['pipe-schedules']['walls'][$size['nps']][$schedule]??null;
        if (!is_numeric($wall) || (float)$wall<=0) {
            $add('REFERENCE_PAIR_NOT_LOADED','REVIEW_REQUIRED','properties.schedule','Size/schedule pair is not available in the loaded table; no thickness or universal size limit is inferred.','ترکیب سایز و اسکجول در جدول موجود نیست؛ ضخامت یا محدوده استاندارد استنتاج نمی‌شود.'); return;
        }
        $compare('wall_thickness_mm',(float)$wall,'pipe-schedules '.$packs['pipe-schedules']['version'].'; NPS '.$size['nps'].'; schedule '.$schedule);
    }
}
