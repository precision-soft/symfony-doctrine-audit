<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

final class TransactionDto
{
    /** @param array<string, mixed> $extras */
    public function __construct(
        private readonly string $username,
        private readonly array $extras = [],
    ) {}

    public function getUsername(): string
    {
        return $this->username;
    }

    /** @return array<string, mixed> */
    public function getExtras(): array
    {
        return $this->extras;
    }
}
