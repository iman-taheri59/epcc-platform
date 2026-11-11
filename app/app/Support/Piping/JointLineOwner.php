<?php

declare(strict_types=1);

namespace App\Support\Piping;

/** Which line's joint register and sequence own a branch joint. */
enum JointLineOwner: string
{
    case BRANCH = 'BRANCH';

    case HEADER = 'HEADER';
}
