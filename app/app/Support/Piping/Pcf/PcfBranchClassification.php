<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\P2pKind;

/**
 * Read-only interpretation of explicit PCF branch vocabulary, not a component
 * template. P2P has no purchased fitting and must never map to OLET.
 */
final class PcfBranchClassification
{
    /**
     * Classify only unambiguous type-only vocabulary; broad PCF aliases need
     * the component's SKEY and must go through fromComponent().
     *
     * @return array{connection_type:string,p2p_kind:?string}|null Unknown remains unresolved.
     */
    public static function fromType(string $pcfType): ?array
    {
        return match (strtoupper(trim($pcfType))) {
            'TEE-SET-IN' => ['connection_type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN],
            'TEE', 'TEE-SKEWED' => ['connection_type' => BranchConnectionType::TEE, 'p2p_kind' => null],
            'WELDOLET' => ['connection_type' => BranchConnectionType::WELDOLET, 'p2p_kind' => null],
            'SOCKOLET' => ['connection_type' => BranchConnectionType::SOCKOLET, 'p2p_kind' => null],
            default => null,
        };
    }

    /**
     * Classify a component using the PCF SKEY where the type name is broad.
     *
     * The standard uses TEE-SET-ON and TEE-STUB for both set-on and stub-in
     * variants. Their SKEY is the distinguishing data: TESO/TERF are set-on;
     * TSSO/TSRF are stub-in. Missing or unrecognised SKEYs stay unresolved.
     * CROSS-* entries have multiple branch legs and need per-leg mapping, so
     * they are deliberately not collapsed into this single-branch result.
     *
     * @return array{connection_type:string,p2p_kind:?string}|null
     */
    public static function fromComponent(PcfComponent $component): ?array
    {
        $type = strtoupper(trim($component->type));
        $skey = strtoupper((string) $component->attribute('SKEY'));

        if (in_array($type, ['TEE-SET-ON', 'TEE-STUB'], true)) {
            return match ($skey) {
                'TESO', 'TERF' => ['connection_type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_ON],
                'TSSO', 'TSRF' => ['connection_type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN],
                default => null,
            };
        }

        return self::fromType($type);
    }

    /**
     * Types that look branch-specific but cannot safely be treated as ordinary
     * fittings when their classification is absent or ambiguous.
     */
    public static function isBranchLikeType(string $pcfType): bool
    {
        return in_array(strtoupper(trim($pcfType)), [
            'OLET',
            'TEE-SET',
            'TEE-SET-ON',
            'TEE-SET-IN',
            'TEE-STUB',
            'CROSS-SET-ON',
            'CROSS-STUB',
        ], true);
    }
}
