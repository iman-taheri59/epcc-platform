<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Piping\Pcf\PcfBranchClassification;
use App\Support\Piping\Pcf\PcfComponent;
use PHPUnit\Framework\TestCase;

final class PcfBranchClassificationTest extends TestCase
{
    public function test_only_unambiguous_type_names_classify_without_component_data(): void
    {
        // TEE-SET-ON is broad in PCF and needs its SKEY; do not guess from type.
        $this->assertNull(PcfBranchClassification::fromType('TEE-SET-ON'));
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

    public function test_pcf_skey_disambiguates_set_on_and_stub_in_tee_aliases(): void
    {
        $this->assertSame(
            ['connection_type' => 'P2P', 'p2p_kind' => 'STUB_ON'],
            PcfBranchClassification::fromComponent(new PcfComponent(
                'TEE-STUB', [], ['SKEY' => 'TESO'], 1,
            )),
        );
        $this->assertSame(
            ['connection_type' => 'P2P', 'p2p_kind' => 'STUB_IN'],
            PcfBranchClassification::fromComponent(new PcfComponent(
                'TEE-SET-ON', [], ['SKEY' => 'TSSO'], 1,
            )),
        );
    }

    public function test_broad_tee_aliases_without_a_disambiguating_skey_stay_unresolved(): void
    {
        $this->assertNull(PcfBranchClassification::fromComponent(
            new PcfComponent('TEE-STUB', [], [], 1),
        ));
    }

    public function test_cross_types_need_per_branch_mapping_and_generic_olet_stays_ambiguous(): void
    {
        foreach (['CROSS-SET-ON', 'CROSS-STUB', 'OLET'] as $type) {
            $this->assertNull(PcfBranchClassification::fromComponent(
                new PcfComponent($type, [], [], 1),
            ));
            $this->assertTrue(PcfBranchClassification::isBranchLikeType($type));
        }
    }
}
