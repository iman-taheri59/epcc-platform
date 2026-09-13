<?php

declare(strict_types=1);

namespace Epcc\StandardsEngine;

/** A single failed release-gate rule, with bilingual reason text. */
final class Failure implements \JsonSerializable
{
    public function __construct(
        public readonly string $check,
        public readonly string $messageEn,
        public readonly string $messageFa,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'check'   => $this->check,
            'message' => ['en' => $this->messageEn, 'fa' => $this->messageFa],
        ];
    }
}
