<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

/** @todo add the option of extra data */
final class TransactionDto
{
    public function __construct(
        private readonly string $username,
    ) {}

    public function getUsername(): string
    {
        return $this->username;
    }
}
