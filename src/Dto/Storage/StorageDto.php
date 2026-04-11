<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

class StorageDto
{
    /** @param EntityDto[] $entities */
    public function __construct(
        private readonly TransactionDto $transaction,
        private readonly array $entities,
    ) {}

    public function getTransaction(): TransactionDto
    {
        return $this->transaction;
    }

    /** @return EntityDto[] */
    public function getEntities(): array
    {
        return $this->entities;
    }
}
