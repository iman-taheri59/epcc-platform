<?php

declare(strict_types=1);

namespace App\Support\Units\Exceptions;

use RuntimeException;

/**
 * A conversion that is not arithmetic.
 *
 * Two cases: different quantities (pressure to temperature), and different
 * datums within one quantity (gauge to absolute). The second is the subtle
 * one - the factors are identical, so the conversion looks harmless, but the
 * offset between them is the site's atmospheric pressure and therefore an
 * engineering decision rather than a constant.
 */
final class IncompatibleUnitException extends RuntimeException
{
}
