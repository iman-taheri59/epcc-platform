<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Standard;
use App\Services\StandardsLibrary;
use Illuminate\Database\Seeder;

/**
 * The starting catalogue: the codes an Iranian oil & gas EPCC project actually
 * cites, with Persian titles.
 *
 * Editions are the ones current at the time of writing. They are the
 * CATALOGUE's editions — a project pins its own in `project_standards`, so
 * refreshing this list can never move a project off the edition its contract
 * names.
 */
final class StandardsLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $library = app(StandardsLibrary::class);

        foreach ($this->catalogue() as $row) {
            $library->define(
                body: $row[0],
                designation: $row[1],
                titleEn: $row[2],
                titleFa: $row[3],
                edition: $row[4],
                category: $row[5],
            );
        }
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string,4:?string,5:string}>
     */
    private function catalogue(): array
    {
        return [
            // ---------------------------------------------------------- ASME
            ['ASME', 'B31.3', 'Process Piping', 'لوله‌کشی فرآیندی', '2022', Standard::PIPING],
            ['ASME', 'B31.4', 'Pipeline Transportation Systems for Liquids and Slurries', 'سامانه‌های خطوط لوله انتقال مایعات و دوغاب', '2022', Standard::PIPING],
            ['ASME', 'B31.8', 'Gas Transmission and Distribution Piping Systems', 'سامانه‌های لوله‌کشی انتقال و توزیع گاز', '2022', Standard::PIPING],
            ['ASME', 'B31.1', 'Power Piping', 'لوله‌کشی نیروگاهی', '2022', Standard::PIPING],
            ['ASME', 'B16.5', 'Pipe Flanges and Flanged Fittings NPS 1/2 through NPS 24', 'فلنج‌ها و اتصالات فلنجی لوله از ۱/۲ تا ۲۴ اینچ', '2020', Standard::PIPING],
            ['ASME', 'B16.9', 'Factory-Made Wrought Buttwelding Fittings', 'اتصالات جوشی سربه‌سر کارخانه‌ای', '2018', Standard::PIPING],
            ['ASME', 'B16.11', 'Forged Fittings, Socket-Welding and Threaded', 'اتصالات فورج‌شده، جوش کاسه‌ای و دنده‌ای', '2021', Standard::PIPING],
            ['ASME', 'B16.20', 'Metallic Gaskets for Pipe Flanges', 'واشرهای فلزی فلنج لوله', '2017', Standard::PIPING],
            ['ASME', 'B16.34', 'Valves — Flanged, Threaded and Welding End', 'شیرآلات — انتهای فلنجی، دنده‌ای و جوشی', '2020', Standard::PIPING],
            ['ASME', 'B16.47', 'Large Diameter Steel Flanges NPS 26 through NPS 60', 'فلنج‌های فولادی قطر بزرگ از ۲۶ تا ۶۰ اینچ', '2020', Standard::PIPING],
            ['ASME', 'B36.10M', 'Welded and Seamless Wrought Steel Pipe', 'لوله فولادی جوشی و بدون درز', '2022', Standard::PIPING],
            ['ASME', 'B36.19M', 'Stainless Steel Pipe', 'لوله فولاد زنگ‌نزن', '2018', Standard::PIPING],
            ['ASME', 'BPVC Section V', 'Nondestructive Examination', 'آزمون‌های غیرمخرب', '2023', Standard::NDE],
            ['ASME', 'BPVC Section VIII Div 1', 'Rules for Construction of Pressure Vessels', 'قواعد ساخت مخازن تحت فشار', '2023', Standard::MATERIAL],
            ['ASME', 'BPVC Section IX', 'Welding, Brazing and Fusing Qualifications', 'صلاحیت جوشکاری، لحیم‌کاری و ذوب', '2023', Standard::WELDING],
            ['ASME', 'BPVC Section II Part C', 'Specifications for Welding Rods, Electrodes and Filler Metals', 'مشخصات میله‌های جوشکاری، الکترودها و فلزات پرکننده', '2023', Standard::WELDING],
            ['ASME', 'PCC-1', 'Guidelines for Pressure Boundary Bolted Flange Joint Assembly', 'راهنمای مونتاژ اتصالات فلنجی پیچ‌ومهره‌ای مرز فشار', '2022', Standard::PIPING],

            // ----------------------------------------------------------- API
            ['API', '5L', 'Line Pipe', 'لوله خطوط انتقال', '2018', Standard::MATERIAL],
            ['API', '6D', 'Pipeline and Piping Valves', 'شیرآلات خطوط لوله و لوله‌کشی', '2021', Standard::PIPING],
            ['API', '600', 'Steel Gate Valves', 'شیرهای کشویی فولادی', '2021', Standard::PIPING],
            ['API', '602', 'Gate, Globe and Check Valves for Petroleum and Natural Gas', 'شیرهای کشویی، کروی و یک‌طرفه نفت و گاز', '2022', Standard::PIPING],
            ['API', '610', 'Centrifugal Pumps for Petroleum, Petrochemical and Natural Gas Industries', 'پمپ‌های گریز از مرکز صنایع نفت، پتروشیمی و گاز', '2021', Standard::MATERIAL],
            ['API', '650', 'Welded Tanks for Oil Storage', 'مخازن جوشی ذخیره نفت', '2020', Standard::MATERIAL],
            ['API', '653', 'Tank Inspection, Repair, Alteration and Reconstruction', 'بازرسی، تعمیر، تغییر و بازسازی مخازن', '2020', Standard::QUALITY],
            ['API', '660', 'Shell-and-Tube Heat Exchangers', 'مبدل‌های حرارتی پوسته و لوله', '2015', Standard::MATERIAL],
            ['API', 'RP 578', 'Guidelines for a Material Verification Program (PMI)', 'راهنمای برنامه تأیید جنس متریال (PMI)', '2018', Standard::MATERIAL],
            ['API', 'RP 582', 'Welding Guidelines for the Chemical, Oil and Gas Industries', 'راهنمای جوشکاری صنایع شیمیایی، نفت و گاز', '2016', Standard::WELDING],
            ['API', '1104', 'Welding of Pipelines and Related Facilities', 'جوشکاری خطوط لوله و تأسیسات مرتبط', '2021', Standard::WELDING],
            ['API', 'RP 1169', 'Basic Inspection Requirements — Pipeline Construction', 'الزامات پایه بازرسی — ساخت خط لوله', '2020', Standard::QUALITY],

            // ------------------------------------------------------------ EN
            ['EN', '10204', 'Metallic Products — Types of Inspection Documents', 'محصولات فلزی — انواع مدارک بازرسی', '2004', Standard::MATERIAL],
            ['EN', '10025', 'Hot Rolled Products of Structural Steels', 'محصولات نورد گرم فولادهای ساختمانی', '2019', Standard::CIVIL],
            ['EN', '13480', 'Metallic Industrial Piping', 'لوله‌کشی صنعتی فلزی', '2020', Standard::PIPING],
            ['EN', 'ISO 15614-1', 'Specification and Qualification of Welding Procedures', 'تعیین و تأیید صلاحیت دستورالعمل‌های جوشکاری', '2017', Standard::WELDING],

            // ----------------------------------------------------------- ISO
            ['ISO', '9001', 'Quality Management Systems — Requirements', 'سامانه‌های مدیریت کیفیت — الزامات', '2015', Standard::QUALITY],
            ['ISO', '14001', 'Environmental Management Systems', 'سامانه‌های مدیریت زیست‌محیطی', '2015', Standard::HSE],
            ['ISO', '45001', 'Occupational Health and Safety Management Systems', 'سامانه‌های مدیریت ایمنی و بهداشت شغلی', '2018', Standard::HSE],
            ['ISO', '19650-1', 'Organization of Information about Construction Works — Concepts and Principles', 'سازمان‌دهی اطلاعات کارهای ساختمانی — مفاهیم و اصول', '2018', Standard::QUALITY],
            ['ISO', '19650-2', 'Organization of Information — Delivery Phase of the Assets', 'سازمان‌دهی اطلاعات — فاز تحویل دارایی‌ها', '2018', Standard::QUALITY],
            ['ISO', '3834-2', 'Quality Requirements for Fusion Welding — Comprehensive', 'الزامات کیفی جوشکاری ذوبی — جامع', '2021', Standard::WELDING],
            ['ISO', '17637', 'Non-destructive Testing of Welds — Visual Testing', 'آزمون غیرمخرب جوش — آزمون چشمی', '2016', Standard::NDE],
            ['ISO', '17636-1', 'NDT of Welds — Radiographic Testing', 'آزمون غیرمخرب جوش — پرتونگاری', '2022', Standard::NDE],
            ['ISO', '9712', 'Qualification and Certification of NDT Personnel', 'صلاحیت و گواهی‌نامه کارکنان آزمون غیرمخرب', '2021', Standard::NDE],

            // ---------------------------------------------------------- ASTM
            ['ASTM', 'A106', 'Seamless Carbon Steel Pipe for High-Temperature Service', 'لوله فولاد کربنی بدون درز سرویس دما بالا', '2019', Standard::MATERIAL],
            ['ASTM', 'A312', 'Seamless and Welded Austenitic Stainless Steel Pipes', 'لوله‌های فولاد زنگ‌نزن آستنیتی بدون درز و جوشی', '2022', Standard::MATERIAL],
            ['ASTM', 'A234', 'Piping Fittings of Wrought Carbon Steel and Alloy Steel', 'اتصالات لوله فولاد کربنی و آلیاژی', '2021', Standard::MATERIAL],
            ['ASTM', 'A105', 'Carbon Steel Forgings for Piping Applications', 'قطعات فورج فولاد کربنی کاربردهای لوله‌کشی', '2021', Standard::MATERIAL],
            ['ASTM', 'A193', 'Alloy-Steel and Stainless Steel Bolting for High Temperature', 'پیچ و مهره فولاد آلیاژی و زنگ‌نزن دما بالا', '2020', Standard::MATERIAL],
            ['ASTM', 'E94', 'Standard Guide for Radiographic Examination', 'راهنمای آزمون پرتونگاری', '2017', Standard::NDE],

            // ---------------------------------------------------------- NACE
            ['NACE', 'SP0490', 'Holiday Detection of Fusion-Bonded Epoxy External Coatings', 'آشکارسازی منفذ پوشش‌های اپوکسی چسبیده ذوبی بیرونی', '2007', Standard::MATERIAL],
            ['NACE', 'MR0175', 'Materials for Use in H2S-Containing Environments', 'مواد برای محیط‌های حاوی سولفید هیدروژن', '2021', Standard::MATERIAL],
            ['NACE', 'SP0169', 'Control of External Corrosion on Buried or Submerged Piping', 'کنترل خوردگی بیرونی لوله‌های مدفون یا غوطه‌ور', '2013', Standard::MATERIAL],

            // ----------------------------------------------------------- AWS
            ['AWS', 'D1.1', 'Structural Welding Code — Steel', 'کد جوشکاری سازه — فولاد', '2020', Standard::WELDING],

            // ------------------------------------------------- Electrical / I&C
            ['IEC', '60079', 'Explosive Atmospheres', 'اتمسفرهای انفجاری', '2019', Standard::ELECTRICAL],
            ['IEC', '61511', 'Functional Safety — Safety Instrumented Systems', 'ایمنی عملکردی — سامانه‌های ابزاردقیق ایمنی', '2016', Standard::INSTRUMENT],
            ['IEEE', '80', 'Guide for Safety in AC Substation Grounding', 'راهنمای ایمنی زمین‌کردن پست‌های فشار قوی', '2013', Standard::ELECTRICAL],
            ['ISA', '5.1', 'Instrumentation Symbols and Identification', 'نمادها و شناسه‌گذاری ابزار دقیق', '2022', Standard::INSTRUMENT],

            // --------------------------------------------------------- Iranian
            ['IPS', 'G-ME-100', 'General Standard for Piping Materials (Iranian Petroleum Standard)', 'استاندارد عمومی متریال لوله‌کشی (استاندارد نفت ایران)', null, Standard::PIPING],
            ['IPS', 'C-TP-102', 'Construction Standard for Painting', 'استاندارد اجرایی رنگ‌آمیزی', null, Standard::MATERIAL],
            ['IPS', 'G-SF-900', 'General Standard for Fire Fighting', 'استاندارد عمومی اطفای حریق', null, Standard::HSE],
        ];
    }
}
