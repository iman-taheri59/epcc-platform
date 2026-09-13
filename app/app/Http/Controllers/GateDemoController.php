<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StandardsPackRepository;
use Epcc\StandardsEngine\ReleaseGateEvaluator;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

final class GateDemoController extends Controller
{
    public function __construct(
        private readonly StandardsPackRepository $packs,
        private readonly ReleaseGateEvaluator $evaluator,
    ) {
    }

    public function __invoke(Request $request): View
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fa';
        $pack = $this->packs->get('ips-iran');

        // Toggleable facts, so the gate can be driven live from the UI.
        //
        // An unchecked HTML checkbox is simply absent from the query string, so
        // a plain `boolean($k, true)` default would make the boxes impossible to
        // turn off. The hidden `submitted` marker distinguishes "first visit"
        // (use friendly defaults) from "user submitted the form" (absence means
        // deliberately unchecked).
        $submitted  = $request->boolean('submitted');
        $flag = static fn (string $key, bool $default): bool => $submitted
            ? $request->boolean($key)
            : $default;

        $sheets     = $flag('sheets', true);
        $consultant = $flag('consultant', true);
        $deductions = $flag('deductions', true);
        $overLimit  = $flag('over', false);

        $net = 43_234_500_000;

        $facts = [
            'measurementSheets' => $sheets ? ['MS-001', 'MS-002'] : [],
            'verifiedBy'        => $consultant ? ['CONSULTANT'] : ['CONTRACTOR'],
            'deductions'        => $deductions
                ? ['retention' => 4_825_000_000, 'advanceRecovery' => 9_650_000_000, 'priceAdjustment' => 6_110_000_000, 'tax' => 4_341_450_000]
                : ['retention' => 4_825_000_000, 'advanceRecovery' => null, 'priceAdjustment' => null, 'tax' => null],
            'netAmount'              => $net,
            'approverAuthorityLimit' => $overLimit ? 10_000_000_000 : 50_000_000_000,
        ];

        $verdict = $this->evaluator->evaluate($pack, 'interimPaymentCertificate', $facts);

        $today = Jalalian::now();
        $jalali = $today->format('Y/m/d');

        return view('gate-demo', [
            'lang'        => $lang,
            'dir'         => $lang === 'fa' ? 'rtl' : 'ltr',
            'verdict'     => $verdict,
            'facts'       => $facts,
            'net'         => $net,
            'packId'      => $pack['packId'],
            'packVersion' => $pack['version'],
            'jalaliDate'  => $lang === 'fa' ? CalendarUtils::convertNumbers($jalali) : $jalali,
            'jalaliMonth' => $today->format('%B'),
            'toggles'     => compact('sheets', 'consultant', 'deductions', 'overLimit'),
        ]);
    }
}
