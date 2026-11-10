<?php

declare(strict_types=1);

namespace App\Support\Piping\Exceptions;

use RuntimeException;

/**
 * A component has a port whose role is BRANCH, but its template is not one we
 * know how to classify.
 *
 * Raised rather than swallowed. The alternative — recording no branch kind and
 * carrying on — is precisely the failure this whole feature exists to prevent:
 * the joint would be an ordinary weld on every cost report while being a branch
 * connection on the isometric. Silence here is worse than a refusal, because a
 * refusal sends someone to look at the reference pack.
 *
 * Reaching this means the reference pack gained a branch-port template that
 * JointBranchKind::fromTemplate() does not name yet. The fix is to name it, not
 * to catch this.
 */
final class UnknownBranchTemplate extends RuntimeException
{
    public static function forTemplate(string $templateCode): self
    {
        return new self(
            "Template {$templateCode} declares a BRANCH port but JointBranchKind "
            .'does not know it. Add it there; a branch connection recorded as an '
            .'ordinary weld understates the work.',
        );
    }
}
