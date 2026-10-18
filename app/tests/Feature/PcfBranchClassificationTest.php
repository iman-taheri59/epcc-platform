<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Piping\Pcf\PcfBranchClassification;
use PHPUnit\Framework\TestCase;

final class PcfBranchClassificationTest extends TestCase
{
    public function test_set_on_and_set_in_are_p2p_without_a_fitting(): void
    {
        $this->assertSame(['connection_type' => 'P2P', 'p2p_kind' => 'STUB_ON'], PcfBranchClassification::fromType('TEE-SET-ON'));
        $this->assertSame(['connection_type' => 'P2P', 'p2p_kind' => 'STUB_IN'], PcfBranchClassification::fromType('tee-set-in'));
    }

    public function test_actual_tee_and_named_olets_keep_their_identity(): void
    {
        foreach (['TEE' => 'TEE', 'WELDOLET' => 'WELDOLET', 'SOCKOLET' => 'SOCKOLET'] as $pcf => $expected) {
            $this->assertSame(['connection_type' => $expected, 'p2p_kind' => null], PcfBranchClassification::fromType($pcf));
        }
    }

    public function test_ambiguous_or_unknown_types_are_not_guessed(): void
    {
        foreach (['OLET', 'TEE-SET', 'FLUX-CAPACITOR', ''] as $pcf) {
            $this->assertNull(PcfBranchClassification::fromType($pcf));
        }
    }
}
