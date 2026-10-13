<?php

declare(strict_types=1);

namespace App\Support\Piping\Exceptions;

use RuntimeException;

/**
 * A branch bigger than the header it comes off (ADR-008 §4.4).
 *
 * Deliberately a DIFFERENT exception from NoRuleForSizeException, because the
 * two have completely different causes and completely different fixes. This
 * one means the MODEL is wrong - somebody drew a 6" branch off a 2" header.
 * The other means the PMS is incomplete. Collapsing them into "no rule found"
 * would send an engineer to read a specification when the drawing is at fault.
 *
 * This cannot be a row-level CHECK: it is a property of the query, not of any
 * stored rule.
 */
final class BranchLargerThanHeaderException extends RuntimeException
{
    public function __construct(
        public readonly int $headerDn,
        public readonly int $branchDn,
    ) {
        parent::__construct(
            "A branch cannot be larger than its header: DN{$branchDn} branch off a DN{$headerDn} header. "
            .'This is a modelling error, not a gap in the PMS.'
        );
    }
}
