<?php

declare(strict_types=1);

namespace App\Support\Piping\Exceptions;

use RuntimeException;

/**
 * The PMS does not cover this size for this class.
 *
 * THROWS rather than returning null, for the same reason ADR-007's reference
 * lookups do: a silent null here becomes a silent default schedule, which
 * becomes a wrong wall thickness on a pressure line. A gap in the PMS is an
 * engineering error and is worth surfacing loudly.
 */
final class NoRuleForSizeException extends RuntimeException
{
    public function __construct(
        public readonly string $classCode,
        public readonly string $ruleKind,
        public readonly int $dn,
    ) {
        parent::__construct(
            "Piping class '{$classCode}' has no {$ruleKind} rule covering DN{$dn}. "
            .'The PMS does not specify this size for this class.'
        );
    }
}
