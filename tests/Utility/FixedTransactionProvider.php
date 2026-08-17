<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;

/** @internal */
final class FixedTransactionProvider implements TransactionProviderInterface
{
    /** @param array<string, mixed> $extras */
    public function __construct(
        private readonly string $username = 'integration',
        private readonly array $extras = [],
    ) {}

    public function getTransaction(): TransactionDto
    {
        return new TransactionDto($this->username, $this->extras);
    }
}
