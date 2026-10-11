<?php

declare(strict_types=1);

namespace App\Support\Piping\Exceptions;

use RuntimeException;

/**
 * A branch DN larger than its header DN is modelling nonsense (ADR-008 §4.4).
 * Kept distinct from "no rule found": one is a data-entry error in the model,
 * the other is a gap in the PMS — completely different causes.
 */
final class BranchLargerThanHeaderException extends RuntimeException
{
    public function __construct(
        public readonly int $headerDn,
        public readonly int $branchDn,
    ) {
        parent::__construct("Branch DN{$branchDn} is larger than header DN{$headerDn}.");
    }
}
