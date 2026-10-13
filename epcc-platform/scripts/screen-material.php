<?php

declare(strict_types=1);
// File-only diagnostic: no Laravel boot, database connection, network request or writes.
require dirname(__DIR__).'/app/app/Services/Engineering/MaterialScreening.php';
use App\Services\Engineering\MaterialScreening;
try {
    if ($argc<2 || $argc>3 || ($argc===3 && $argv[2]!=='--html')) {
        throw new RuntimeException('Usage: php scripts/screen-material.php INPUT.json [--html]');
    }
    $path=$argv[1];
    if (!is_file($path) || !is_readable($path) || filesize($path)>1048576) {
        throw new RuntimeException('Input must be a readable local JSON file, at most 1 MiB.');
    }
    $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) { throw new RuntimeException('Expected one JSON declaration object.'); }
    $result=(new MaterialScreening(dirname(__DIR__).'/standards-packs/reference'))->check($data);
    if ($argc===2) { echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; exit(0); }
    $e=fn ($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>غربالگری اطلاعات متریال</title><style>body{font:16px/1.9 Tahoma,sans-serif;background:#f4f6fa;color:#162138;max-width:1000px;margin:auto;padding:24px}article,section{background:white;border:1px solid #d8deea;border-radius:12px;margin:14px 0;padding:18px}code,pre{direction:ltr;text-align:left;overflow-wrap:anywhere;white-space:pre-wrap}h1{font-size:26px}.DISCREPANCY{border-right:5px solid #b91c1c}.INCOMPLETE{border-right:5px solid #b7791f}.REVIEW_REQUIRED{border-right:5px solid #4169a1}small{color:#4b5563}</style><h1>غربالگری اطلاعات متریال</h1><p>این گزارش تأیید مهندسی یا مجوز مصرف در PMS، مدل یا MTO نیست. هیچ مقدار منبعی تغییر نکرده است.</p><p>وضعیت: <bdi>'.$e($result['status']).'</bdi> · نسخهٔ قواعد: <bdi>'.$e($result['ruleset']).'</bdi></p>';
    foreach ($result['findings'] as $f) {
        echo '<article class="'.$e($f['severity']).'"><bdi>'.$e($f['code']).' · '.$e($f['severity']).'</bdi><p>'.$e($f['message']['fa']).'</p><p lang="en" dir="ltr">'.$e($f['message']['en']).'</p><small><bdi>'.$e($f['field']).' — '.$e($f['basis']).'</bdi></small></article>';
    }
    echo '<section><h2>منابع مقایسه و اثرانگشت</h2><pre>'.$e(json_encode($result['references'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre><p><bdi>'.$e($result['input_sha256']).'</bdi></p></section><section><h2>اطلاعات ورودی بدون تغییر</h2><pre>'.$e(json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></section></html>';
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
