<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Auditor;

class CollectionChangeDto
{
    /**
     * The identifier arguments are the ones the auditor could already read during `onFlush`. They matter for a
     * deletion: the orm clears a removed entity's generated identifier once the delete has run, so by `postFlush`
     * the owner of a deleted collection can no longer say who it was.
     *
     * @param object[] $addedEntities
     * @param object[] $removedEntities
     * @param array<string, mixed>|null $ownerIdentifier
     * @param list<array<string, mixed>>|null $addedIdentifiers
     * @param list<array<string, mixed>>|null $removedIdentifiers
     */
    public function __construct(
        protected readonly object $owner,
        protected readonly string $field,
        protected readonly string $targetClass,
        protected readonly array $addedEntities,
        protected readonly array $removedEntities,
        protected readonly ?array $ownerIdentifier = null,
        protected readonly ?array $addedIdentifiers = null,
        protected readonly ?array $removedIdentifiers = null,
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

    /** @return array<string, mixed>|null */
    public function getOwnerIdentifier(): ?array
    {
        return $this->ownerIdentifier;
    }

    /** @return list<array<string, mixed>>|null */
    public function getAddedIdentifiers(): ?array
    {
        return $this->addedIdentifiers;
    }

    /** @return list<array<string, mixed>>|null */
    public function getRemovedIdentifiers(): ?array
    {
        return $this->removedIdentifiers;
    }
}
