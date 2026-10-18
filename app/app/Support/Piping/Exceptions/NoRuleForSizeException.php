<?php

declare(strict_types=1);

namespace App\Support\Piping\Exceptions;

use RuntimeException;

/**
 * The PMS does not cover this size for this class. Thrown rather than
 * returning null (ADR-008 §3.2): a silent null becomes a silent default
 * schedule, which becomes a wrong wall thickness on a pressure line.
 */
final class NoRuleForSizeException extends RuntimeException
{
    public function __construct(
        public readonly string $pipingClassId,
        public readonly string $subject,
        public readonly int $dn,
    ) {
        parent::__construct("No {$subject} rule covers DN{$dn} for piping class {$pipingClassId}.");
    }
}
