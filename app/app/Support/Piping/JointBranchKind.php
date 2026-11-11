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
 *   - `JointBranchKind` is what the MODEL OBSERVES: this joint sits on a
 *     TEE/CROSS branch port, or is an explicitly constructed wall weld (OLET,
 *     STUB_IN or STUB_ON).
 *
 * ADR-008 §9.2 anticipates the comparison: "a joint contradicting its class is
 * a deviation". The register keeps its own observation rather than overwriting
 * it with the class verdict.
 *
 * Classification and valuation stay separate. The owner's requirement is that
 * branch connections can be priced separately from ordinary welds; the rate
 * table, not this enum or dia-inch, assigns value.
 *
 * TEE and CROSS branch kinds are derived from a BRANCH port and its template.
 * OLET is different: its class-level branch weld is the OLET RUN/FA-to-header
 * wall weld, recorded explicitly by `JointService::joinOletToWall()`. The
 * separate OLET BRANCH-to-pipe weld is an ordinary two-port weld and must not
 * be counted as a second branch connection. `STUB_IN` and `STUB_ON` remain the
 * fitting-less wall kinds supplied by `joinToWall()` (ADR-006 §14).
 */
final class JointBranchKind
{
    /** Branch port of a tee. The branch weld itself is an ordinary butt weld. */
    public const TEE = 'TEE';

    /** Branch port of a cross - two opposed branches off one run. */
    public const CROSS = 'CROSS';

    /** OLET RUN/FA-to-header-wall branch weld; not the outlet butt weld. */
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
     * OLET, STUB_IN and STUB_ON are explicit wall-joint kinds.
     *
     * @var list<string>
     */
    public const DERIVABLE = [
        self::TEE,
        self::CROSS,
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
     * The kind a two-port BRANCH-role weld implies, by template code.
     *
     * TEE and CROSS are observable at their branch-port weld. OLET is
     * deliberately omitted: its class-level branch weld is the separate
     * RUN-to-header-wall joint and is written explicitly by joinOletToWall().
     * A newly introduced branch-port template must be named here or
     * branchKindFor() fails loudly rather than making it an invisible branch.
     */
    public static function fromTemplate(string $templateCode): ?string
    {
        return match ($templateCode) {
            'TEE', 'TEE-SKEWED' => self::TEE,
            'CROSS' => self::CROSS,
            default => null,
        };
    }
}
