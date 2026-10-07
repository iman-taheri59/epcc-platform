<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\Project;
use App\Services\MaterialCatalogueService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/** Synthetic Piping material catalogue for the MTO foundation. */
final class MaterialCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class);
        $catalogue = app(MaterialCatalogueService::class);

        $project = $tenant->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
        $pars = $tenant->forIdentityLookup(fn () => Company::where('code', 'EPC-PARS')->firstOrFail());

        $items = [
            [
                'category' => MaterialItem::CATEGORY_PIPE,
                'commodity_code' => 'PIP-CS-6-SCH40',
                'description_en' => '6" carbon steel pipe, SCH 40, API 5L X60 PSL2',
                'description_fa' => 'لوله فولادی کربنی ۶ اینچ، رده ۴۰، API 5L X60 PSL2',
                'base_unit' => 'M',
                'size_nps' => '6"',
                'size_dn' => 'DN150',
                'schedule' => 'SCH40',
                'class_code' => 'CS-150',
                'material_spec' => 'API 5L PSL2',
                'grade' => 'X60',
                'alias_codes' => ['PIPE-6-CS-S40'],
            ],
            [
                'category' => MaterialItem::CATEGORY_VALVE,
                'commodity_code' => 'VLV-GT-6-150',
                'description_en' => '6" gate valve, ASME 150, carbon steel',
                'description_fa' => 'شیر دروازه‌ای ۶ اینچ، کلاس ۱۵۰، فولاد کربنی',
                'base_unit' => 'EA',
                'size_nps' => '6"',
                'size_dn' => 'DN150',
                'rating' => 'ASME 150',
                'class_code' => 'CS-150',
                'material_spec' => 'API 600 / ASME B16.34',
                'alias_codes' => ['GV-6-150-CS'],
            ],
            [
                'category' => MaterialItem::CATEGORY_GASKET,
                'commodity_code' => 'GSK-SPW-6-150-SS-GR',
                'description_en' => '6" ASME 150 spiral wound gasket, SS316/graphite',
                'description_fa' => 'گسکت اسپیرال وند ۶ اینچ کلاس ۱۵۰، SS316/گرافیت',
                'base_unit' => 'EA',
                'size_nps' => '6"',
                'size_dn' => 'DN150',
                'rating' => 'ASME 150',
                'class_code' => 'CS-150',
                'material_spec' => 'ASME B16.20',
                'alias_codes' => ['GASK-6-150'],
            ],
        ];

        foreach ($items as $item) {
            $tenant->runAs($pars->id, fn () => $catalogue->upsertProjectItem(
                $project,
                ['discipline' => 'PIPING', 'source' => MaterialItem::SOURCE_PROJECT_CATALOGUE] + $item,
                'fa'
            ), $project->id);
        }
    }
}
