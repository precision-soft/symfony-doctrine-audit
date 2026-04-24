<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Auditor;

use Doctrine\ORM\PersistentCollection;

class AuditorDto
{
    /** @var EntityDto[] */
    protected array $auditEntities;

    /**
     * @param object[] $entitiesToDelete
     * @param object[] $entitiesToInsert
     * @param object[] $entitiesToUpdate
     * @param array<string, array<string, array{0: mixed, 1: mixed}|PersistentCollection<int, object>>> $entityChangeSets
     */
    public function __construct(
        protected readonly array $entitiesToDelete,
        protected readonly array $entitiesToInsert,
        protected readonly array $entitiesToUpdate,
        protected readonly array $entityChangeSets = [],
    ) {
        $this->auditEntities = [];
    }

    /** @return object[] */
    public function getEntitiesToDelete(): array
    {
        return $this->entitiesToDelete;
    }

    /** @return object[] */
    public function getEntitiesToInsert(): array
    {
        return $this->entitiesToInsert;
    }

    /** @return object[] */
    public function getEntitiesToUpdate(): array
    {
        return $this->entitiesToUpdate;
    }

    /** @return EntityDto[] */
    public function getAuditEntities(): array
    {
        return $this->auditEntities;
    }

    public function addAuditEntity(EntityDto $entityDto): static
    {
        $this->auditEntities[] = $entityDto;

        return $this;
    }

    /** @return array<string, array{0: mixed, 1: mixed}|PersistentCollection<int, object>>|null */
    public function getEntityChangeSet(object $entity): ?array
    {
        return $this->entityChangeSets[\spl_object_hash($entity)] ?? null;
    }
}
