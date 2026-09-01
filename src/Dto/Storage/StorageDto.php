<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

class StorageDto
{
    /**
     * @param EntityDto[] $entities
     * @param CollectionChangeDto[] $collectionChanges
     */
    public function __construct(
        protected readonly TransactionDto $transaction,
        protected readonly array $entities,
        protected readonly array $collectionChanges = [],
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

    /** @return CollectionChangeDto[] */
    public function getCollectionChanges(): array
    {
        return $this->collectionChanges;
    }

    /** @return list<array<string, mixed>> */
    public function getCollectionChangesAsArray(): array
    {
        return \array_map(
            static fn(CollectionChangeDto $collectionChangeDto) => $collectionChangeDto->toArray(),
            \array_values($this->collectionChanges),
        );
    }
}
