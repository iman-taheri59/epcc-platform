<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * What makes a joint a BRANCH connection, as opposed to a weld in the run.
 *
 * This is the vocabulary of the joint register, and it is deliberately not the
 * vocabulary of the PMS branch table (`BranchConnectionType`). The two answer
 * different questions and both are needed:
 *
 *   - `BranchConnectionType` is what the CLASS DECLARES for a header x branch
 *     size pair: TEE, WELDOLET, SOCKOLET, or P2P.
 *   - `JointBranchKind` is what the MODEL OBSERVES: this joint sits on the
 *     branch port of a multi-way fitting, or is an explicit wall weld.
 *
 * ADR-008 §9.2 anticipates the comparison: "a joint contradicting its class is
 * a deviation". The register keeps its own observation rather than overwriting
 * it with the class verdict.
 *
 * Classification and valuation stay separate. The owner's requirement is that
 * branch connections can be priced separately from ordinary welds; the rate
 * table, not this enum or dia-inch, assigns value.
 *
 * Fitting branch kinds (TEE, CROSS, OLET) are derived from the branch port and
 * its template. `STUB_IN` and `STUB_ON` are supplied by the explicit wall-joint
 * path (`JointService::joinToWall`) and carry a frozen header reference; they
 * cannot be inferred from ports alone (ADR-006 §14).
 */
final class JointBranchKind
{
    /** Branch port of a tee. The branch weld itself is an ordinary butt weld. */
    public const TEE = 'TEE';

    /** Branch port of a cross - two opposed branches off one run. */
    public const CROSS = 'CROSS';

    /** Branch port of an olet. Note: this is the olet-to-BRANCH-PIPE weld. */
    public const OLET = 'OLET';

    /** Fitting-less branch inserted into a header bore; explicit wall-joint kind. */
    public const STUB_IN = 'STUB_IN';

    /** Fitting-less branch saddled onto a header surface; explicit wall-joint kind. */
    public const STUB_ON = 'STUB_ON';

    /** @var list<string> */
    public const ALL = [
        self::TEE,
        self::CROSS,
        self::OLET,
        self::STUB_IN,
        self::STUB_ON,
    ];

    /**
     * Kinds that can be inferred from a port role and fitting template alone.
     * Wall-joint kinds are explicit and use a separate construction path.
     *
     * @var list<string>
     */
    public const DERIVABLE = [
        self::TEE,
        self::CROSS,
        self::OLET,
    ];

    /** @return array<string, array{en: string, fa: string}> */
    public static function labels(): array
    {
        return [
            self::TEE => ['en' => 'Branch through a tee', 'fa' => 'انشعاب از طریق سه‌راهی'],
            self::CROSS => ['en' => 'Branch through a cross', 'fa' => 'انشعاب از طریق چهارراهی'],
            self::OLET => ['en' => 'Branch through an olet', 'fa' => 'انشعاب از طریق اولِت'],
            self::STUB_IN => ['en' => 'Stub-in on a header', 'fa' => 'انشعابِ نفوذی روی هدر'],
            self::STUB_ON => ['en' => 'Stub-on a header', 'fa' => 'انشعابِ سوار بر هدر'],
        ];
    }

    /**
     * The kind a component's BRANCH-role port implies, by template code.
     *
     * Only TEE, CROSS and OLET have branch ports in the reference pack. A
     * template with a branch port that is not listed here is a reference-pack
     * change, and returning null would let it become an invisible branch - so
     * it is a hard failure instead.
     */
    public static function fromTemplate(string $templateCode): ?string
    {
        return match ($templateCode) {
            'TEE', 'TEE-SKEWED' => self::TEE,
            'CROSS' => self::CROSS,
            'OLET' => self::OLET,
            default => null,
        };
    }
}
