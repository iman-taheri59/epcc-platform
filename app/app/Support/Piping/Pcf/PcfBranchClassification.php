<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\P2pKind;

/**
 * Read-only interpretation of an explicit PCF branch type, not a component
 * template. P2P has no purchased fitting and must never map to OLET.
 */
final class PcfBranchClassification
{
    /** @return array{connection_type:string,p2p_kind:?string}|null Unknown remains unresolved. */
    public static function fromType(string $pcfType): ?array
    {
        return match (strtoupper(trim($pcfType))) {
            'TEE-SET-ON' => ['connection_type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_ON],
            'TEE-SET-IN' => ['connection_type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN],
            'TEE', 'TEE-SKEWED' => ['connection_type' => BranchConnectionType::TEE, 'p2p_kind' => null],
            'WELDOLET' => ['connection_type' => BranchConnectionType::WELDOLET, 'p2p_kind' => null],
            'SOCKOLET' => ['connection_type' => BranchConnectionType::SOCKOLET, 'p2p_kind' => null],
            default => null,
        };
    }
}
