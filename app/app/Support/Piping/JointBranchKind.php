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
 *     branch port of a multi-way fitting.
 *
 * ADR-008 §9.2 anticipates the comparison: "a joint contradicting its class is
 * a deviation". That check is only possible if the register keeps its own
 * observation rather than overwriting it with the class verdict.
 *
 * Careful about what this does and does not mean commercially. The owner's
 * requirement (2026-11-10) is that a branch connection costs more to execute
 * than an ordinary weld, so the system must be able to separate the two. It
 * can now. But the premium is not uniform, and the register does not decide
 * it:
 *
 *   - a branch through a TEE is an ordinary circumferential butt weld; what
 *     costs extra there is the fitting and the two extra welds in the run,
 *     plus fit-up on a three-way joint;
 *   - the genuinely expensive welds are the ones ON THE HEADER WALL - an
 *     olet's fabricated end welded onto the header, and a stub-in / stub-on.
 *     Both are contour welds: the header is cut, the branch end is coped to
 *     match, and the weld follows a saddle rather than a flat girth.
 *
 * The wall-side welds are NOT representable yet - an olet's RUN port carries
 * end type FA and connects to nothing, and a stub-in has no port on the header
 * at all (ADR-006 §13, P-22). So `STUB_IN` and `STUB_ON` are reserved here and
 * are not produced by any code path today. Declaring them now keeps the CHECK
 * honest about the shape the column will eventually take, and stops a future
 * writer from inventing a spelling; it is not a claim that P2P works.
 */
final class JointBranchKind
{
    /** Branch port of a tee. The branch weld itself is an ordinary butt weld. */
    public const TEE = 'TEE';

    /** Branch port of a cross - two opposed branches off one run. */
    public const CROSS = 'CROSS';

    /** Branch port of an olet. Note: this is the olet-to-BRANCH-PIPE weld. */
    public const OLET = 'OLET';

    /**
     * Reserved for P-22 / ADR-008 §9.2. A stub-in sits inside the header bore.
     * NOT PRODUCED BY ANY CODE PATH - see the class docblock.
     */
    public const STUB_IN = 'STUB_IN';

    /** Reserved for P-22. A stub-on saddles the header surface. Not produced. */
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
     * What the model can actually derive today, from a port's role. Anything
     * outside this set needs the header-wall reference (P-22) and cannot be
     * inferred from the ports alone.
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
