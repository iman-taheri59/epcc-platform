<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\StandardsPackRepository;
use Epcc\StandardsEngine\ReleaseGateEvaluator;
use Tests\TestCase;

final class ReleaseGateTest extends TestCase
{
    private function facts(array $overrides = []): array
    {
        return array_merge([
            'measurementSheets'      => ['MS-001'],
            'verifiedBy'             => ['CONSULTANT'],
            'deductions'             => ['retention' => 1, 'advanceRecovery' => 1, 'priceAdjustment' => 1, 'tax' => 1],
            'netAmount'              => 1_000,
            'approverAuthorityLimit' => 50_000,
        ], $overrides);
    }

    public function test_engine_is_resolvable_and_framework_free(): void
    {
        $ev = app(ReleaseGateEvaluator::class);
        $this->assertInstanceOf(ReleaseGateEvaluator::class, $ev);

        // The engine package must not depend on Laravel (ADR-003 Annex A §6).
        $composer = json_decode(
            (string) file_get_contents(base_path('../packages/standards-engine/composer.json')),
            true,
        );
        $this->assertSame(['php' => '^8.3'], $composer['require']);
    }

    public function test_all_three_packs_load(): void
    {
        $repo = app(StandardsPackRepository::class);
        $this->assertSame(['awp', 'ips-iran', 'iso'], $repo->available());
        $this->assertSame('ips.iran', $repo->get('ips-iran')['packId']);
    }

    public function test_complete_certificate_clears_the_gate(): void
    {
        $v = app(ReleaseGateEvaluator::class)->evaluate(
            app(StandardsPackRepository::class)->get('ips-iran'),
            'interimPaymentCertificate',
            $this->facts(),
        );

        $this->assertTrue($v->passed);
        $this->assertFalse($v->isBlocked());
    }

    public function test_soratvaziat_gate_is_never_waivable(): void
    {
        $v = app(ReleaseGateEvaluator::class)->evaluate(
            app(StandardsPackRepository::class)->get('ips-iran'),
            'interimPaymentCertificate',
            $this->facts(['measurementSheets' => []]),
        );

        $this->assertTrue($v->isAbsolutelyBlocked(), 'The payment certificate gate must never be waivable.');
        $this->assertFalse($v->isWaivable());
        $this->assertContains('measurementSheetsAttached', $v->failedChecks());
    }

    public function test_every_failure_carries_persian_and_english_text(): void
    {
        $v = app(ReleaseGateEvaluator::class)->evaluate(
            app(StandardsPackRepository::class)->get('ips-iran'),
            'interimPaymentCertificate',
            ['measurementSheets' => [], 'verifiedBy' => [], 'deductions' => []],
        );

        $this->assertNotEmpty($v->failures);
        foreach ($v->failures as $f) {
            $this->assertNotSame('', trim($f->messageEn), "missing EN for {$f->check}");
            $this->assertNotSame('', trim($f->messageFa), "missing FA for {$f->check}");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $f->messageFa, "FA text for {$f->check} is not Persian");
        }
    }

    public function test_awp_pack_still_evaluates_generically(): void
    {
        // ADR-002 seam guard: the engine must stay pack-driven, not IPS-specific.
        $v = app(ReleaseGateEvaluator::class)->evaluate(
            app(StandardsPackRepository::class)->get('awp'),
            'iwp',
            ['constraints' => [['hard' => true, 'cleared' => false]], 'drawings' => [['status' => 'IFC']],
             'materialAvailabilityPct' => 100, 'permit' => ['validForWindow' => true],
             'estimatedHours' => 10, 'maxHours' => 100],
        );

        $this->assertFalse($v->passed);
        $this->assertTrue($v->isWaivable());
        $this->assertSame('iwp.waiver.approve', $v->waiverCapability);
    }
}
