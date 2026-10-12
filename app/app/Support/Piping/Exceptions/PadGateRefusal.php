<?php

declare(strict_types=1);

namespace App\Support\Piping\Exceptions;

use RuntimeException;

/**
 * One (header DN, branch DN) pair of a branch-table cell cannot be checked
 * by the para 304.3.3 gate — missing pack data, an out-of-range condition,
 * or a geometry outside para 304.3.1(b). Collected per pair by
 * BranchConnectionPadGate so one bad cell does not hide the rest of the
 * report; the publish gate refuses the class while any refusal stands.
 */
final class PadGateRefusal extends RuntimeException
{
}
