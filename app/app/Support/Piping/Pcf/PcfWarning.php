<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/** Something the parser could not make sense of, reported rather than dropped. */
final readonly class PcfWarning
{
    public function __construct(
        public int $sourceLine,
        public string $code,
        public string $detail = '',
    ) {}
}
