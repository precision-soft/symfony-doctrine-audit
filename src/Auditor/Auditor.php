<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Auditor;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto as AnnotationEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\AuditorDto;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\EntityDto as AuditorEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto as StorageEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Throwable;

class Auditor
{
    use ThrowTrait;

    /** @var array<string, AnnotationEntityDto>|null */
    private ?array $auditedEntities;
    private ?AuditorDto $auditorDto;

    /**
     * @param StorageInterface[] $storages
     */
    public function __construct(
        private readonly Configuration $configuration,
        private readonly EntityManagerInterface $entityManager,
        private readonly array $storages,
        private readonly TransactionProviderInterface $transactionProvider,
        private readonly ?LoggerInterface $logger,
        private readonly AnnotationReadServiceInterface $annotationReadService,
    ) {
        $this->auditedEntities = null;
        $this->auditorDto = null;
    }

    /**
     * Deletions are captured in onFlush because entities are still in the identity map.
     * Inserts and updates are deferred to postFlush so that generated identifiers (e.g. auto-increment)
     * are available and change-sets are final.
     */
    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        /**
         * @info SDA-102 rollback safety: clear any stale auditor dto carried over from a previous flush
         *   whose transaction rolled back (postFlush is not dispatched on rollback and therefore never
         *   reaches its own finally-reset). Without this, the next successful flush would emit a
         *   phantom audit row built from the previous, rolled-back change-set.
         */
        $this->auditorDto = null;

        try {
            $this->auditedEntities ??= $this->annotationReadService->read($this->entityManager);

            $unitOfWork = $this->entityManager->getUnitOfWork();

            $entitiesToDelete = $this->filterAuditedEntities($unitOfWork->getScheduledEntityDeletions());

            $entitiesToInsert = $this->filterAuditedEntities($unitOfWork->getScheduledEntityInsertions());

            $entitiesToUpdate = $this->filterAuditedEntities($unitOfWork->getScheduledEntityUpdates());

            if ([] === $entitiesToDelete && [] === $entitiesToInsert && [] === $entitiesToUpdate) {
                return;
            }

            $changeSets = [];
            foreach ($entitiesToUpdate as $entity) {
                $changeSets[\spl_object_hash($entity)] = $unitOfWork->getEntityChangeSet($entity);
            }

            $this->auditorDto = new AuditorDto($entitiesToDelete, $entitiesToInsert, $entitiesToUpdate, $changeSets);

            $this->createAuditEntities($entitiesToDelete, Operation::Delete);
        } catch (Throwable $throwable) {
            $this->auditorDto = null;
            $this->throw($throwable);
        }
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        $storageDto = null;

        try {
            if (null === $this->auditorDto) {
                return;
            }

            $entitiesToInsert = $this->auditorDto->getEntitiesToInsert();
            $entitiesToUpdate = $this->auditorDto->getEntitiesToUpdate();

            $this->createAuditEntities($entitiesToInsert, Operation::Insert);
            $this->createAuditEntities($entitiesToUpdate, Operation::Update);

            $storageDto = $this->createStorageDto();

            $this->save($storageDto);
            /** @info forces a GC cycle after each flush to prevent memory accumulation in high-volume audit scenarios */
            \gc_collect_cycles();
        } catch (Throwable $throwable) {
            /**
             * @info SDA-101 silent-audit-loss guard: the outer Doctrine transaction has already committed
             *   by the time postFlush runs. If every configured audit sink fails, the audit row would be
             *   silently lost. Re-queue-on-failure emits the full storage payload to the logger so the
             *   record can be recovered or replayed out-of-band before re-throwing.
             */
            if (null !== $storageDto) {
                $this->getLogger()?->critical(
                    'audit_dead_letter: every configured audit storage failed; payload preserved here for manual recovery',
                    [
                        'exception' => $throwable,
                        'storage_dto' => $storageDto,
                    ],
                );
            }

            $this->throw($throwable);
        } finally {
            $this->auditorDto = null;
        }
    }

    protected function save(StorageDto $storageDto): void
    {
        $exceptions = [];

        foreach ($this->storages as $storage) {
            try {
                $storage->save($storageDto);
            } catch (Throwable $throwable) {
                $exceptions[] = $throwable;
                $this->getLogger()?->error('audit storage failed', ['exception' => $throwable]);
            }
        }

        if ([] !== $exceptions) {
            throw $exceptions[0];
        }
    }

    /** @param object[] $entities */
    protected function createAuditEntities(array $entities, Operation $operation): void
    {
        if (null === $this->auditorDto) {
            throw new Exception('createAuditEntities called without an active auditor dto; onFlush must run first');
        }
        $unitOfWork = $this->entityManager->getUnitOfWork();

        foreach ($entities as $entity) {
            $entityData = \array_merge(
                $this->getOriginalEntityData($entity),
                $unitOfWork->getEntityIdentifier($entity),
            );

            $changeSet = Operation::Update === $operation
                ? $this->auditorDto->getEntityChangeSet($entity)
                : null;

            $entityDtos = $this->createAuditorEntityDtos(
                $this->entityManager->getClassMetadata($this->annotationReadService->getEntityClass($entity)),
                $entityData,
                $operation,
                $changeSet,
            );

            foreach ($entityDtos as $entityDto) {
                $this->auditorDto->addAuditEntity($entityDto);
            }
        }
    }

    /**
     * @phpstan-param ClassMetadata<object> $classMetadata
     * @param array<string, mixed> $entityData
     * @param array<string, array{0: mixed, 1: mixed}|PersistentCollection<int, object>>|null $changeSet
     * @return array<int, AuditorEntityDto>
     */
    protected function createAuditorEntityDtos(
        ClassMetadata $classMetadata,
        array $entityData,
        Operation $operation,
        ?array $changeSet = null,
    ): array {
        $entityDtos = [];

        $unitOfWork = $this->entityManager->getUnitOfWork();

        $auditorEntityDto = new AuditorEntityDto(
            $operation,
            $classMetadata->getName(),
            $this->getTableName($classMetadata),
        );

        $entityDtos[] = $auditorEntityDto;

        foreach ($classMetadata->getAssociationMappings() as $field => $association) {
            if (true === $classMetadata->isInheritanceTypeJoined() && true === $classMetadata->isInheritedAssociation($field)) {
                continue;
            }

            $isToOne = ($association['type'] & ClassMetadata::TO_ONE) > 0;
            $isOwningSide = true === $association['isOwningSide'];

            if (false === ($isToOne && $isOwningSide)) {
                /** @info to-many / inverse-side association changes are surfaced through PersistentCollection entries in the change-set and are not tracked here; see SDA-106 for full collection-change support */
                continue;
            }

            $fieldChangeSet = $this->getScalarChangeSetEntry($changeSet, $field);
            $hasOldValue = null !== $fieldChangeSet;

            /** @info for UPDATE the entity's new association target is $fieldChangeSet[1]; $entityData is populated from UnitOfWork::getOriginalEntityData() and therefore still holds the old reference */
            $associationData = true === $hasOldValue
                ? $fieldChangeSet[1]
                : ($entityData[$field] ?? null);

            $relatedId = null;

            if (null !== $associationData && true === $unitOfWork->isInIdentityMap($associationData)) {
                $relatedId = $unitOfWork->getEntityIdentifier($associationData);
            }

            $targetClassMetadata = $this->entityManager->getClassMetadata($association['targetEntity']);

            foreach ($association['joinColumns'] as $joinColumn) {
                $sourceColumn = $joinColumn['name'];

                $targetFieldName = $targetClassMetadata->getFieldName($joinColumn['referencedColumnName']);
                $fieldType = $targetClassMetadata->getTypeOfField($targetFieldName) ?? Types::STRING;

                $relatedFieldValue = $relatedId[$targetFieldName] ?? null;

                $oldRelatedFieldValue = null;

                if (true === $hasOldValue) {
                    $oldAssociationData = $fieldChangeSet[0];
                    if (null !== $oldAssociationData && true === $unitOfWork->isInIdentityMap($oldAssociationData)) {
                        $oldRelatedId = $unitOfWork->getEntityIdentifier($oldAssociationData);
                        $oldRelatedFieldValue = $oldRelatedId[$targetFieldName] ?? null;
                    }
                }

                $auditorEntityDto->addField(
                    new FieldDto($field, $sourceColumn, $fieldType, $relatedFieldValue, $oldRelatedFieldValue, $hasOldValue),
                );
            }
        }

        foreach ($classMetadata->getFieldNames() as $field) {
            if (true === $classMetadata->isInheritanceTypeJoined() && true === $classMetadata->isInheritedField($field) && false === $classMetadata->isIdentifier($field)) {
                continue;
            }

            $columnName = $this->getColumnName($field, $classMetadata);

            $fieldMapping = $classMetadata->getFieldMapping($field);
            $fieldType = $fieldMapping->type;

            $fieldChangeSet = $this->getScalarChangeSetEntry($changeSet, $field);
            $hasOldValue = null !== $fieldChangeSet;

            /** @info for UPDATE the new value lives in $fieldChangeSet[1]; $entityData is populated from UnitOfWork::getOriginalEntityData() and therefore still holds the old value */
            $fieldValue = true === $hasOldValue
                ? $fieldChangeSet[1]
                : ($entityData[$field] ?? null);
            $oldValue = true === $hasOldValue ? $fieldChangeSet[0] : null;

            $auditorEntityDto->addField(
                new FieldDto($field, $columnName, $fieldType, $fieldValue, $oldValue, $hasOldValue),
            );
        }

        if (true === $classMetadata->isInheritanceTypeSingleTable()) {
            $discriminatorColumn = $classMetadata->discriminatorColumn;
            \assert(null !== $discriminatorColumn);
            $auditorEntityDto->addField(
                new FieldDto(
                    $discriminatorColumn['fieldName'],
                    $discriminatorColumn['name'],
                    $discriminatorColumn['type'],
                    $classMetadata->discriminatorValue,
                ),
            );
        }

        if (true === $classMetadata->isInheritanceTypeJoined()) {
            $discriminatorColumn = $classMetadata->discriminatorColumn;
            \assert(null !== $discriminatorColumn);
            $field = $discriminatorColumn['fieldName'];

            if (true === $classMetadata->isRootEntity()) {
                $auditorEntityDto->addField(
                    new FieldDto(
                        $field,
                        $discriminatorColumn['name'],
                        $discriminatorColumn['type'],
                        $entityData[$field] ?? null,
                    ),
                );
            } else {
                $entityData[$field] = $classMetadata->discriminatorValue;

                $entityDtos = \array_merge(
                    $this->createAuditorEntityDtos(
                        $this->entityManager->getClassMetadata($classMetadata->rootEntityName),
                        $entityData,
                        $operation,
                        $changeSet,
                    ),
                    $entityDtos,
                );
            }
        }

        return $entityDtos;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}|PersistentCollection<int, object>>|null $changeSet
     * @return array{0: mixed, 1: mixed}|null
     */
    protected function getScalarChangeSetEntry(?array $changeSet, string $field): ?array
    {
        if (null === $changeSet) {
            return null;
        }

        if (false === \array_key_exists($field, $changeSet)) {
            return null;
        }

        $entry = $changeSet[$field];

        if (true === $entry instanceof PersistentCollection) {
            /** @info PersistentCollection change-set entries belong to to-many associations and are not tracked here; see SDA-106 */
            return null;
        }

        return $entry;
    }

    /** @phpstan-param ClassMetadata<object> $classMetadata */
    protected function getTableName(ClassMetadata $classMetadata): string
    {
        $quoteStrategy = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();

        return $quoteStrategy->getTableName($classMetadata, $platform);
    }

    /** @phpstan-param ClassMetadata<object> $classMetadata */
    protected function getColumnName(string $field, ClassMetadata $classMetadata): string
    {
        $quoteStrategy = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();

        return $quoteStrategy->getColumnName($field, $classMetadata, $platform);
    }

    /** @return array<string, mixed> */
    protected function getOriginalEntityData(object $entity): array
    {
        $classMetadata = $this->entityManager->getClassMetadata($this->annotationReadService->getEntityClass($entity));
        $originalEntityData = $this->entityManager->getUnitOfWork()->getOriginalEntityData($entity);

        if (true === $classMetadata->isVersioned && null !== $classMetadata->versionField) {
            $versionField = $classMetadata->versionField;
            $originalEntityData[$versionField] = $classMetadata->reflFields[$versionField]->getValue($entity);
        }

        return $originalEntityData;
    }

    protected function createStorageDto(): StorageDto
    {
        if (null === $this->auditorDto) {
            throw new Exception('createStorageDto called without an active auditor dto; onFlush must run first');
        }
        $transaction = $this->transactionProvider->getTransaction();

        $entities = [];

        foreach ($this->auditorDto->getAuditEntities() as $entityDto) {
            if (false === isset($this->auditedEntities[$entityDto->getClass()])) {
                /** @info entity not registered for auditing — skipped intentionally; this may indicate a configuration issue */
                continue;
            }

            /** @var AnnotationEntityDto $annotationEntityDto */
            $annotationEntityDto = $this->auditedEntities[$entityDto->getClass()];

            $fields = [];
            foreach ($entityDto->getFields() as $fieldDto) {
                if (true === \in_array($fieldDto->getName(), $annotationEntityDto->getIgnoredFields(), true)) {
                    continue;
                }

                if (true === \in_array($fieldDto->getName(), $this->configuration->getIgnoredFields(), true)) {
                    continue;
                }

                $fields[] = $fieldDto;
            }

            if ([] === $fields) {
                throw new Exception(
                    \sprintf(
                        'entity `%s` has all fields ignored — review @Ignore annotations and the global ignored_fields configuration',
                        $entityDto->getClass(),
                    ),
                );
            }

            $entities[] = new StorageEntityDto(
                $entityDto->getOperation(),
                $entityDto->getClass(),
                $entityDto->getTableName(),
                $fields,
            );
        }

        return new StorageDto($transaction, $entities);
    }

    /**
     * @param object[] $allEntities
     * @return object[]
     */
    protected function filterAuditedEntities(array $allEntities): array
    {
        $entities = [];

        foreach ($allEntities as $entity) {
            $hash = \spl_object_hash($entity);

            if (true === isset($entities[$hash])) {
                continue;
            }

            if (false === $this->hasAuditedEntity($this->annotationReadService->getEntityClass($entity))) {
                continue;
            }

            $entities[$hash] = $entity;
        }

        return \array_values($entities);
    }

    protected function hasAuditedEntity(string $entityClass): bool
    {
        return true === isset($this->auditedEntities[$entityClass]);
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
