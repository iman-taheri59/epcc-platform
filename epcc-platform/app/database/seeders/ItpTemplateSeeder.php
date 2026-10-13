<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ItpTemplate;
use App\Support\Itp\InterventionCode as IC;
use App\Support\TenantType as T;
use Illuminate\Database\Seeder;

/**
 * The standard ITP library, starting with incoming material inspection.
 *
 * PIPING AND PIPELINE ARE SEPARATE, by the user's instruction, and the
 * difference is not cosmetic:
 *
 *   - Plant piping is governed by ASME B31.3. Inspection is lot-based, the
 *     PMC is the engineer of record, and PMI (positive material
 *     identification) matters most for alloy service.
 *   - A cross-country pipeline is governed by B31.4 / B31.8 and API 5L. Line
 *     pipe arrives by the wagon-load with mill certificates per heat, and the
 *     inspections that matter are heat traceability, end-bevel condition and
 *     coating integrity - none of which appear on a plant piping ITP.
 *
 * Folding them together would force one set of hold points onto both, and the
 * first thing a real QA manager would do is override it.
 *
 * The party grids below are a defensible default, not a law: the project edits
 * them. Hold points are kept few and placed where a defect is either
 * irreversible or invisible later - which is the whole art of an ITP.
 */
final class ItpTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $this->pipingIncomingMaterial();
        $this->pipelineIncomingMaterial();
    }

    private function pipingIncomingMaterial(): void
    {
        ItpTemplate::updateOrCreate(
            ['code' => 'STD-PIPING-MAT-01'],
            [
                'discipline' => 'PIPING',
                'title_en' => 'Incoming material inspection — plant piping',
                'title_fa' => 'بازرسی ورود متریال — لوله‌کشی واحد',
                'scope_en' => 'Receipt, identification and release of piping material: pipe, fittings, flanges, gaskets, bolting and valves, to ASME B31.3.',
                'scope_fa' => 'ورود، شناسایی و ترخیص متریال لوله‌کشی: لوله، اتصالات، فلنج، واشر، پیچ و مهره و شیرآلات، مطابق ASME B31.3.',
                'revision_label' => 'Rev 0',
                'is_active' => true,
                'stages' => [
                    [
                        'clause' => '1.1',
                        'sequence' => 10,
                        'title_en' => 'Review of mill test certificates (MTC / EN 10204 3.1)',
                        'title_fa' => 'بررسی گواهی‌های کارخانه (MTC / EN 10204 3.1)',
                        'acceptance_en' => 'Certificate traceable to heat number; chemistry and mechanical properties within the material specification; certificate type 3.1 or 3.2 as required by the PO.',
                        'acceptance_fa' => 'گواهی قابل ردیابی به شمارهٔ ذوب؛ آنالیز شیمیایی و خواص مکانیکی در محدودهٔ مشخصات؛ نوع گواهی 3.1 یا 3.2 مطابق سفارش خرید.',
                        'method' => 'DR',
                        'frequency_en' => 'Every heat / every lot',
                        'frequency_fa' => 'هر ذوب / هر لات',
                        'reference' => 'EN 10204 · ASME B31.3 §323',
                        'record_en' => 'MTC review sheet',
                        'record_fa' => 'برگهٔ بررسی MTC',
                        'notice_hours' => null,
                        // The standard template cites project documents by
                        // NUMBER: the library has no project to hold a key into.
                        // ItpService resolves these against the project register
                        // and skips any this project never issued.
                        'form_number' => 'QCF-PIP-001',
                        'documents' => [
                            ['document_number' => 'PH-14-PIPING-SPEC-0001', 'usage' => 'SPEC', 'locator' => '§7.3'],
                            ['document_number' => 'PH-14-PIPING-FORM-0001', 'usage' => 'FORM'],
                        ],
                        // A document review, so nobody stands in a yard - but
                        // the PMC HOLDS it. An unverifiable certificate is the
                        // one defect that can never be found later by looking
                        // at the steel.
                        'parties' => [T::EPC => IC::REVIEW, T::PMC => IC::HOLD, T::TPI => IC::REVIEW, T::OWN => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.2',
                        'sequence' => 20,
                        'title_en' => 'Visual and dimensional inspection',
                        'title_fa' => 'بازرسی چشمی و ابعادی',
                        'acceptance_en' => 'No transit damage, corrosion or laminations. Outside diameter, wall thickness, length, ovality and end bevel within ASME B36.10M / B16.9 tolerance.',
                        'acceptance_fa' => 'بدون آسیب حمل، خوردگی یا لایه‌لایه‌شدن. قطر خارجی، ضخامت جداره، طول، بیضی‌شدگی و پخ انتها در محدودهٔ ASME B36.10M / B16.9.',
                        'method' => 'VI + DI',
                        'frequency_en' => '100% visual; dimensional 10% sample, minimum 3 pieces',
                        'frequency_fa' => 'چشمی ۱۰۰٪؛ ابعادی نمونهٔ ۱۰٪ و حداقل ۳ عدد',
                        'reference' => 'ASME B36.10M · B16.9 · B16.5',
                        'record_en' => 'Incoming inspection report',
                        'record_fa' => 'گزارش بازرسی ورودی',
                        'notice_hours' => 24,
                        'form_number' => 'QCF-PIP-001',
                        'documents' => [
                            ['document_number' => 'PH-14-PIPING-SPEC-0001', 'usage' => 'SPEC', 'locator' => '§9'],
                            ['document_number' => 'PH-14-PIPING-FORM-0001', 'usage' => 'FORM'],
                        ],
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::WITNESS, T::TPI => IC::WITNESS, T::OWN => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.3',
                        'sequence' => 30,
                        'title_en' => 'Marking and heat-number traceability',
                        'title_fa' => 'مارکینگ و ردیابی شمارهٔ ذوب',
                        'acceptance_en' => 'Each piece carries a legible heat number, size, schedule, material grade and manufacturer mark, matching the MTC and the PO.',
                        'acceptance_fa' => 'روی هر قطعه شمارهٔ ذوب، سایز، رده، گرید و نشان سازنده خوانا و منطبق با MTC و سفارش خرید باشد.',
                        'method' => 'VI',
                        'frequency_en' => '100%',
                        'frequency_fa' => '۱۰۰٪',
                        'reference' => 'ASME B31.3 §323.1',
                        'record_en' => 'Traceability log',
                        'record_fa' => 'دفتر ردیابی',
                        'notice_hours' => 24,
                        'form_number' => 'QCF-PIP-001',
                        'documents' => [
                            ['document_number' => 'PH-14-PIPING-SPEC-0001', 'usage' => 'SPEC', 'locator' => '§8.2'],
                        ],
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::WITNESS, T::TPI => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.4',
                        'sequence' => 40,
                        'title_en' => 'Positive material identification (PMI)',
                        'title_fa' => 'شناسایی مثبت جنس متریال (PMI)',
                        'acceptance_en' => 'Alloy composition matches the specified grade. Mandatory for all alloy and stainless items; carbon steel by sampling where the PO requires it.',
                        'acceptance_fa' => 'ترکیب آلیاژ با گرید مشخص‌شده منطبق باشد. برای تمام اقلام آلیاژی و زنگ‌نزن الزامی؛ فولاد کربنی در صورت الزام سفارش خرید به‌صورت نمونه‌برداری.',
                        'method' => 'PMI (XRF)',
                        'frequency_en' => '100% of alloy items',
                        'frequency_fa' => '۱۰۰٪ اقلام آلیاژی',
                        'reference' => 'API RP 578',
                        'record_en' => 'PMI report',
                        'record_fa' => 'گزارش PMI',
                        'notice_hours' => 48,
                        'form_number' => 'QCF-PIP-014',
                        'documents' => [
                            ['document_number' => 'PH-14-PIPING-SPEC-0001', 'usage' => 'SPEC', 'locator' => '§11'],
                            ['document_number' => 'PH-14-PIPING-FORM-0002', 'usage' => 'FORM'],
                        ],
                        // A HOLD: the wrong alloy in a high-temperature line is
                        // undetectable by eye and fails years later, in service.
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::HOLD, T::TPI => IC::HOLD, T::OWN => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.5',
                        'sequence' => 50,
                        'title_en' => 'Storage, preservation and release',
                        'title_fa' => 'انبارش، نگهداری و ترخیص',
                        'acceptance_en' => 'Material stored off the ground, segregated by grade, end protectors in place; stainless separated from carbon steel to prevent contamination.',
                        'acceptance_fa' => 'متریال روی پایه و جدا از زمین، تفکیک‌شده بر اساس گرید، درپوش انتها نصب؛ زنگ‌نزن جدا از فولاد کربنی برای جلوگیری از آلودگی.',
                        'method' => 'VI',
                        'frequency_en' => 'Each lot on receipt, then monthly',
                        'frequency_fa' => 'هر لات هنگام ورود، سپس ماهانه',
                        'reference' => 'Project preservation procedure',
                        'record_en' => 'Storage checklist',
                        'record_fa' => 'چک‌لیست انبارش',
                        'notice_hours' => null,
                        'form_number' => 'QCF-PIP-022',
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::MONITOR, T::MC => IC::MONITOR],
                    ],
                ],
            ],
        );
    }

    private function pipelineIncomingMaterial(): void
    {
        ItpTemplate::updateOrCreate(
            ['code' => 'STD-PIPELINE-MAT-01'],
            [
                'discipline' => 'PIPELINE',
                'title_en' => 'Incoming material inspection — cross-country pipeline',
                'title_fa' => 'بازرسی ورود متریال — خط لوله برون‌شهری',
                'scope_en' => 'Receipt and release of line pipe, bends, fittings and coating material to API 5L and ASME B31.4 / B31.8.',
                'scope_fa' => 'ورود و ترخیص لولهٔ خط، زانویی، اتصالات و مواد پوشش مطابق API 5L و ASME B31.4 / B31.8.',
                'revision_label' => 'Rev 0',
                'is_active' => true,
                'stages' => [
                    [
                        'clause' => '1.1',
                        'sequence' => 10,
                        'title_en' => 'Mill certificate and heat traceability (API 5L)',
                        'title_fa' => 'گواهی کارخانه و ردیابی ذوب (API 5L)',
                        'acceptance_en' => 'Certificate per heat; chemistry, CE / Pcm, yield, tensile and CVN impact values within the PSL2 requirement of the purchase specification.',
                        'acceptance_fa' => 'گواهی به ازای هر ذوب؛ آنالیز شیمیایی، CE/Pcm، تنش تسلیم، کششی و ضربهٔ CVN مطابق الزامات PSL2 مشخصات خرید.',
                        'method' => 'DR',
                        'frequency_en' => 'Every heat',
                        'frequency_fa' => 'هر ذوب',
                        'reference' => 'API 5L PSL2 · ASME B31.4 §434',
                        'record_en' => 'Certificate review sheet',
                        'record_fa' => 'برگهٔ بررسی گواهی',
                        'notice_hours' => null,
                        'parties' => [T::EPC => IC::REVIEW, T::PMC => IC::HOLD, T::TPI => IC::REVIEW, T::OWN => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.2',
                        'sequence' => 20,
                        'title_en' => 'Pipe body visual, dimensional and end-bevel inspection',
                        'title_fa' => 'بازرسی چشمی، ابعادی و پخ انتهای لوله',
                        'acceptance_en' => 'No dents, gouges or arc burns. Diameter, wall thickness, out-of-roundness, straightness and bevel angle within API 5L tolerance.',
                        'acceptance_fa' => 'بدون فرورفتگی، شیار یا سوختگی قوس. قطر، ضخامت، از گردی خارج‌شدگی، راستی و زاویهٔ پخ در محدودهٔ API 5L.',
                        'method' => 'VI + DI',
                        'frequency_en' => '100% visual on unloading; dimensional 5% per heat',
                        'frequency_fa' => 'چشمی ۱۰۰٪ هنگام تخلیه؛ ابعادی ۵٪ هر ذوب',
                        'reference' => 'API 5L §10',
                        'record_en' => 'Pipe tally and inspection sheet',
                        'record_fa' => 'برگهٔ شمارش و بازرسی لوله',
                        'notice_hours' => 24,
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::WITNESS, T::TPI => IC::HOLD, T::OWN => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.3',
                        'sequence' => 30,
                        'title_en' => 'External coating integrity — holiday detection',
                        'title_fa' => 'سلامت پوشش خارجی — تست منفذیابی',
                        'acceptance_en' => 'No holidays at the specified test voltage; coating thickness within the specification; cut-backs to the required length.',
                        'acceptance_fa' => 'بدون منفذ در ولتاژ آزمون تعیین‌شده؛ ضخامت پوشش مطابق مشخصات؛ طول برش انتهایی مطابق الزام.',
                        'method' => 'Holiday detector + DFT gauge',
                        'frequency_en' => '100% of coated pipe',
                        'frequency_fa' => '۱۰۰٪ لوله‌های پوشش‌دار',
                        'reference' => 'NACE SP0490 · project coating spec',
                        'record_en' => 'Coating inspection report',
                        'record_fa' => 'گزارش بازرسی پوشش',
                        'notice_hours' => 48,
                        // A HOLD: coating damage that reaches the ditch becomes
                        // a corrosion cell buried under two metres of soil.
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::WITNESS, T::TPI => IC::HOLD, T::OWN => IC::MONITOR],
                    ],
                    [
                        'clause' => '1.4',
                        'sequence' => 40,
                        'title_en' => 'Stockpiling, padding and end protection',
                        'title_fa' => 'دپو، تشک‌گذاری و حفاظت انتها',
                        'acceptance_en' => 'Pipe stacked on padded supports to the approved stacking height; end caps fitted; bevels undamaged; no soil contact.',
                        'acceptance_fa' => 'لوله روی تکیه‌گاه تشک‌دار و تا ارتفاع دپوی مصوب؛ درپوش انتها نصب؛ پخ‌ها سالم؛ بدون تماس با خاک.',
                        'method' => 'VI',
                        'frequency_en' => 'Each stockpile, weekly',
                        'frequency_fa' => 'هر دپو، هفتگی',
                        'reference' => 'Project pipe handling procedure',
                        'record_en' => 'Stockpile checklist',
                        'record_fa' => 'چک‌لیست دپو',
                        'notice_hours' => null,
                        'parties' => [T::EPC => IC::WITNESS, T::PMC => IC::MONITOR],
                    ],
                ],
            ],
        );
    }
}
