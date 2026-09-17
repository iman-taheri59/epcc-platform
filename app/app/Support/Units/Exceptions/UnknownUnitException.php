<?php

declare(strict_types=1);

namespace App\Support\Units\Exceptions;

use RuntimeException;

/**
 * A unit the platform does not know, or a value that cannot physically exist.
 *
 * Throws rather than returning null, for the same reason the pipe reference
 * packs do: a silently ignored unit turns "10 psi" into "10" of something, and
 * the number keeps flowing through reports looking legitimate.
 */
final class UnknownUnitException extends RuntimeException
{
}
