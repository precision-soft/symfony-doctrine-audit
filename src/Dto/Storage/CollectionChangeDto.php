<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

class CollectionChangeDto
{
    /**
     * @param array<string, mixed> $ownerIdentifier
     * @param list<array<string, mixed>> $addedIdentifiers
     * @param list<array<string, mixed>> $removedIdentifiers
     */
    public function __construct(
        protected readonly string $ownerClass,
        protected readonly array $ownerIdentifier,
        protected readonly string $field,
        protected readonly string $targetClass,
        protected readonly array $addedIdentifiers,
        protected readonly array $removedIdentifiers,
    ) {}

    /**
     * The shape both storages persist, so doctrine and jsonl stay in parity by construction.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'owner_class' => $this->ownerClass,
            'owner_identifier' => $this->ownerIdentifier,
            'field' => $this->field,
            'target_class' => $this->targetClass,
            'added' => $this->addedIdentifiers,
            'removed' => $this->removedIdentifiers,
        ];
    }

    public function getOwnerClass(): string
    {
        return $this->ownerClass;
    }

    /** @return array<string, mixed> */
    public function getOwnerIdentifier(): array
    {
        return $this->ownerIdentifier;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getTargetClass(): string
    {
        return $this->targetClass;
    }

    /** @return list<array<string, mixed>> */
    public function getAddedIdentifiers(): array
    {
        return $this->addedIdentifiers;
    }

    /** @return list<array<string, mixed>> */
    public function getRemovedIdentifiers(): array
    {
        return $this->removedIdentifiers;
    }
}
