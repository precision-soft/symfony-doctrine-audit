<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Auditor;

class CollectionChangeDto
{
    /**
     * @param object[] $addedEntities
     * @param object[] $removedEntities
     */
    public function __construct(
        protected readonly object $owner,
        protected readonly string $field,
        protected readonly string $targetClass,
        protected readonly array $addedEntities,
        protected readonly array $removedEntities,
    ) {}

    public function getOwner(): object
    {
        return $this->owner;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getTargetClass(): string
    {
        return $this->targetClass;
    }

    /** @return object[] */
    public function getAddedEntities(): array
    {
        return $this->addedEntities;
    }

    /** @return object[] */
    public function getRemovedEntities(): array
    {
        return $this->removedEntities;
    }
}
