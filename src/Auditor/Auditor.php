<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Auditor;

use BackedEnum;
use DateTimeInterface;
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
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\CollectionChangeDto as AuditorCollectionChangeDto;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\EntityDto as AuditorEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\CollectionChangeDto as StorageCollectionChangeDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto as StorageEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Exception\StorageFailureException;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

class Auditor
{
    use ThrowTrait;

    /** @var array<string, AnnotationEntityDto>|null */
    protected ?array $auditedEntities;
    protected ?AuditorDto $auditorDto;

    /**
     * @param StorageInterface[] $storages
     */
    public function __construct(
        protected readonly Configuration $configuration,
        protected readonly EntityManagerInterface $entityManager,
        protected readonly array $storages,
        protected readonly TransactionProviderInterface $transactionProvider,
        protected readonly ?LoggerInterface $logger,
        protected readonly AnnotationReadServiceInterface $annotationReadService,
    ) {
        $this->auditedEntities = null;
        $this->auditorDto = null;
    }

    /** Deletions must be captured here, while the entities are still in the identity map; inserts and updates wait for postFlush, where generated identifiers and final change-sets exist. */
    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        /* postFlush is not dispatched on rollback, so its finally-reset never runs; without this a rolled-back change-set would produce a phantom audit row on the next flush */
        $this->auditorDto = null;

        try {
            $this->auditedEntities ??= $this->annotationReadService->read($this->entityManager);

            $unitOfWork = $this->entityManager->getUnitOfWork();

            $entitiesToDelete = $this->filterAuditedEntities($unitOfWork->getScheduledEntityDeletions());

            $entitiesToInsert = $this->filterAuditedEntities($unitOfWork->getScheduledEntityInsertions());

            $entitiesToUpdate = $this->filterAuditedEntities($unitOfWork->getScheduledEntityUpdates());

            $collectionChanges = $this->createCollectionChanges(
                $unitOfWork->getScheduledCollectionUpdates(),
                $unitOfWork->getScheduledCollectionDeletions(),
                $entitiesToDelete,
            );

            if ([] === $entitiesToDelete && [] === $entitiesToInsert && [] === $entitiesToUpdate && [] === $collectionChanges) {
                return;
            }

            $changeSets = [];
            foreach ($entitiesToUpdate as $entity) {
                $changeSets[\spl_object_hash($entity)] = $unitOfWork->getEntityChangeSet($entity);
            }

            $this->auditorDto = new AuditorDto(
                $entitiesToDelete,
                $entitiesToInsert,
                $entitiesToUpdate,
                $changeSets,
                $collectionChanges,
            );

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
            /* for long-running commands whose object cycles would otherwise accumulate; measured neutral over 1000 inserts, which does not disprove the case it exists for */
            \gc_collect_cycles();
        } catch (Throwable $throwable) {
            $storedPayload = true === $throwable instanceof StorageFailureException
                && true === $throwable->hasStoredPayload();

            /* the outer transaction has already committed by the time postFlush runs, so a total sink failure loses the row silently */
            if (null !== $storageDto && false === $storedPayload) {
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

    /** @throws StorageFailureException if any storage rejected the payload */
    protected function save(StorageDto $storageDto): void
    {
        $exceptions = [];
        $failedStorages = [];
        $storedPayload = false;

        foreach ($this->storages as $storage) {
            try {
                $storage->save($storageDto);
                $storedPayload = true;
            } catch (Throwable $throwable) {
                $exceptions[] = $throwable;
                $failedStorages[] = $storage::class;
                $this->getLogger()?->error(
                    'audit storage failed',
                    ['exception' => $throwable, 'storage' => $storage::class],
                );
            }
        }

        if ([] !== $exceptions) {
            throw new StorageFailureException(
                $exceptions,
                $storedPayload,
                [
                    'failedStorages' => $failedStorages,
                    'storedPayload' => $storedPayload,
                ],
            );
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

            if (false === $isToOne || false === $isOwningSide) {
                /* to-many and inverse-side association changes are not audited (README Limitations) */
                continue;
            }

            $fieldChangeSet = $this->getScalarChangeSetEntry($changeSet, $field);
            $hasOldValue = null !== $fieldChangeSet;

            /* index 1 is the new target; $entityData comes from getOriginalEntityData() and still holds the old reference */
            $associationData = true === $hasOldValue
                ? $fieldChangeSet[1]
                : ($entityData[$field] ?? null);

            $targetClassMetadata = $this->entityManager->getClassMetadata($association['targetEntity']);

            $relatedId = $this->readRelatedIdentifier($associationData, $association['joinColumns'], $targetClassMetadata);

            foreach ($association['joinColumns'] as $joinColumn) {
                $sourceColumn = $joinColumn['name'];

                $targetFieldName = $targetClassMetadata->getFieldName($joinColumn['referencedColumnName']);
                $fieldType = $targetClassMetadata->getTypeOfField($targetFieldName) ?? Types::STRING;

                $relatedFieldValue = $relatedId[$targetFieldName] ?? null;

                $oldRelatedFieldValue = null;

                if (true === $hasOldValue) {
                    $oldRelatedId = $this->readRelatedIdentifier($fieldChangeSet[0], $association['joinColumns'], $targetClassMetadata);
                    $oldRelatedFieldValue = $oldRelatedId[$targetFieldName] ?? null;
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

            /* index 1 is the new value; $entityData comes from getOriginalEntityData() and still holds the old one */
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

            if (null === $discriminatorColumn) {
                throw new Exception(
                    \sprintf('entity `%s` uses inheritance but has no discriminator column', $classMetadata->getName()),
                );
            }
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

            if (null === $discriminatorColumn) {
                throw new Exception(
                    \sprintf('entity `%s` uses inheritance but has no discriminator column', $classMetadata->getName()),
                );
            }
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
            /* getFieldValue() and not $reflFields[…], which is deprecated since ORM 3.3 and removed in ORM 4 */
            $originalEntityData[$versionField] = $classMetadata->getFieldValue($entity, $versionField);
        }

        return $originalEntityData;
    }

    /**
     * @param PersistentCollection<int, object>[] $collectionUpdates
     * @param PersistentCollection<int, object>[] $collectionDeletions
     * @param object[] $entitiesToDelete
     * @return AuditorCollectionChangeDto[]
     */
    protected function createCollectionChanges(array $collectionUpdates, array $collectionDeletions, array $entitiesToDelete = []): array
    {
        $collections = [];

        foreach ($collectionUpdates as $collection) {
            $collections[\spl_object_hash($collection)] = [$collection, false];
        }

        foreach ($collectionDeletions as $collection) {
            $collections[\spl_object_hash($collection)] = [$collection, true];
        }

        $collectionChanges = [];

        foreach ($collections as [$collection, $completeDeletion]) {
            $mapping = $collection->getMapping();
            $owner = $collection->getOwner();

            if (false === $mapping->isOwningSide()
                || null === $owner
                || false === $this->hasAuditedEntity($this->annotationReadService->getEntityClass($owner))
            ) {
                continue;
            }

            $addedEntities = true === $completeDeletion ? [] : $this->filterObjects($collection->getInsertDiff());
            /* `Collection::clear()` takes its snapshot after emptying the collection, so the snapshot is no longer the set that is about to go: the join rows are still in the database at this point and are the only remaining record of it */
            $removedEntities = true === $completeDeletion
                ? $this->readCollectionTargets($owner, $mapping->fieldName, $mapping->targetEntity)
                : $this->filterObjects($collection->getDeleteDiff());

            $collectionChange = $this->createCollectionChange($owner, $mapping->fieldName, $mapping->targetEntity, $addedEntities, $removedEntities);

            if (null === $collectionChange) {
                continue;
            }

            $collectionChanges[$this->getCollectionKey($owner, $mapping->fieldName)] = $collectionChange;
        }

        /* deleting the owner removes its join rows through the persister without scheduling the collection at all, so nothing above ever sees them */
        foreach ($entitiesToDelete as $entity) {
            $classMetadata = $this->entityManager->getClassMetadata(
                $this->annotationReadService->getEntityClass($entity),
            );

            foreach ($classMetadata->getAssociationMappings() as $field => $mapping) {
                if (false === $mapping->isToMany() || false === $mapping->isOwningSide()) {
                    continue;
                }

                $key = $this->getCollectionKey($entity, $field);

                if (true === isset($collectionChanges[$key])) {
                    continue;
                }

                $collectionChange = $this->createCollectionChange(
                    $entity,
                    $field,
                    $mapping->targetEntity,
                    [],
                    $this->readCollectionTargets($entity, $field, $mapping->targetEntity),
                );

                if (null === $collectionChange) {
                    continue;
                }

                $collectionChanges[$key] = $collectionChange;
            }
        }

        return \array_values($collectionChanges);
    }

    protected function getCollectionKey(object $owner, string $field): string
    {
        return \sprintf('%s::%s', \spl_object_hash($owner), $field);
    }

    /**
     * @param object[] $addedEntities
     * @param object[] $removedEntities
     */
    protected function createCollectionChange(object $owner, string $field, string $targetClass, array $addedEntities, array $removedEntities): ?AuditorCollectionChangeDto
    {
        if ([] === $addedEntities && [] === $removedEntities) {
            return null;
        }

        return new AuditorCollectionChangeDto(
            $owner,
            $field,
            $targetClass,
            $addedEntities,
            $removedEntities,
            $this->resolveStableIdentifier($owner),
            $this->resolveStableIdentifiers($addedEntities),
            $this->resolveStableIdentifiers($removedEntities),
        );
    }

    /**
     * The join rows an owning to-many still has in the database. `onFlush` runs before the orm opens its transaction,
     * so this is the last point at which the set a delete or a `clear()` is about to remove can be read at all.
     *
     * @param class-string $targetClass
     * @return object[]
     */
    protected function readCollectionTargets(object $owner, string $field, string $targetClass): array
    {
        $classMetadata = $this->entityManager->getClassMetadata(
            $this->annotationReadService->getEntityClass($owner),
        );
        $identifier = $classMetadata->getIdentifierValues($owner);

        if ([] === $identifier) {
            return [];
        }

        /* the target has to be the root alias: dql refuses to select an entity reached only through a join */
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('target')
            ->from($targetClass, 'target')
            ->from($classMetadata->getName(), 'owner')
            ->andWhere(\sprintf('target MEMBER OF owner.%s', $field));

        foreach (\array_keys($identifier) as $position => $name) {
            $parameter = \sprintf('collectionOwner%d', $position);

            $queryBuilder->andWhere(\sprintf('owner.%s = :%s', $name, $parameter))
                ->setParameter($parameter, $identifier[$name]);
        }

        return $this->filterObjects($queryBuilder->getQuery()->getResult());
    }

    /**
     * @param mixed[] $values
     * @return object[]
     */
    protected function filterObjects(array $values): array
    {
        return \array_values(\array_filter($values, static fn(mixed $value): bool => true === \is_object($value)));
    }

    /**
     * The conversion `postFlush` will do, run while the flush can still be refused: `postFlush` sits after the commit,
     * so an identifier it cannot render there loses the audit trail of a transaction the database has already kept.
     *
     * @return array<string, mixed>|null null when the identifier is not generated yet, which only `postFlush` can read
     */
    protected function resolveStableIdentifier(object $entity): ?array
    {
        $classMetadata = $this->entityManager->getClassMetadata(
            $this->annotationReadService->getEntityClass($entity),
        );
        $identifier = $classMetadata->getIdentifierValues($entity);

        if ([] === $identifier || true === \in_array(null, $identifier, true)) {
            return null;
        }

        return $this->getStableIdentifier($entity);
    }

    /**
     * @param object[] $entities
     * @return list<array<string, mixed>>|null null as soon as one identifier is not readable yet
     */
    protected function resolveStableIdentifiers(array $entities): ?array
    {
        $sorted = [];

        foreach ($entities as $entity) {
            $identifier = $this->resolveStableIdentifier($entity);

            if (null === $identifier) {
                return null;
            }

            $sorted[] = [\json_encode($identifier, \JSON_THROW_ON_ERROR), $identifier];
        }

        return $this->sortByKey($sorted);
    }

    /**
     * An association that is part of the identifier is kept in `getOriginalEntityData()` as the referenced column's
     * value rather than as the target object, so the identity map cannot be asked about it.
     *
     * @param array<int, mixed> $joinColumns
     * @param ClassMetadata<object> $targetClassMetadata
     * @return array<string, mixed>|null
     */
    protected function readRelatedIdentifier(mixed $associationData, array $joinColumns, ClassMetadata $targetClassMetadata): ?array
    {
        if (null === $associationData) {
            return null;
        }

        if (true === \is_object($associationData)) {
            $unitOfWork = $this->entityManager->getUnitOfWork();

            return true === $unitOfWork->isInIdentityMap($associationData)
                ? $unitOfWork->getEntityIdentifier($associationData)
                : null;
        }

        if (1 !== \count($joinColumns)) {
            return null;
        }

        return [
            $targetClassMetadata->getFieldName($joinColumns[0]['referencedColumnName']) => $associationData,
        ];
    }

    /** @return array<string, mixed> */
    protected function getStableIdentifier(object $entity): array
    {
        $classMetadata = $this->entityManager->getClassMetadata(
            $this->annotationReadService->getEntityClass($entity),
        );
        $identifier = $classMetadata->getIdentifierValues($entity);

        if ([] === $identifier) {
            throw new Exception(\sprintf('entity `%s` has no stable identifier', $classMetadata->getName()));
        }

        foreach ($identifier as $field => $value) {
            if (true === $value instanceof BackedEnum) {
                $identifier[$field] = $value->value;
            } elseif (true === $value instanceof DateTimeInterface) {
                $identifier[$field] = $value->format(DateTimeInterface::ATOM);
            } elseif (true === $value instanceof Stringable) {
                /* the shape every uid package maps an identifier to; its string form is what the audit trail has to be comparable on */
                $identifier[$field] = (string)$value;
            } elseif (null !== $value && false === \is_scalar($value)) {
                throw new Exception(
                    \sprintf(
                        'identifier field `%s::%s` is not scalar, so it cannot be recorded as a stable collection identifier; audit the join entity of the association instead',
                        $classMetadata->getName(),
                        $field,
                    ),
                );
            }
        }

        \ksort($identifier);

        return $identifier;
    }

    /**
     * @param object[] $entities
     * @return list<array<string, mixed>>
     */
    protected function getStableIdentifiers(array $entities): array
    {
        $sorted = [];

        foreach ($entities as $entity) {
            $identifier = $this->getStableIdentifier($entity);
            $sorted[] = [\json_encode($identifier, \JSON_THROW_ON_ERROR), $identifier];
        }

        return $this->sortByKey($sorted);
    }

    /**
     * Sorts `[sortKey, value]` pairs and hands back the values. The key is computed once per element rather than on
     * every comparison, which is what a comparator encoding its operands would do.
     *
     * @param list<array{0: string, 1: mixed}> $keyedValues
     * @return list<mixed>
     */
    protected function sortByKey(array $keyedValues): array
    {
        \usort($keyedValues, static fn(array $left, array $right) => $left[0] <=> $right[0]);

        return \array_column($keyedValues, 1);
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
                /* skipped silently: the class is not registered for auditing, which usually means a configuration gap */
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
                        'entity `%s` has all fields ignored — review the ignore annotations and the global ignored_fields configuration',
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

        $sorted = [];

        foreach ($this->auditorDto->getCollectionChanges() as $collectionChange) {
            $owner = $collectionChange->getOwner();

            $storageCollectionChange = new StorageCollectionChangeDto(
                $this->annotationReadService->getEntityClass($owner),
                $collectionChange->getOwnerIdentifier() ?? $this->getStableIdentifier($owner),
                $collectionChange->getField(),
                $collectionChange->getTargetClass(),
                $collectionChange->getAddedIdentifiers() ?? $this->getStableIdentifiers($collectionChange->getAddedEntities()),
                $collectionChange->getRemovedIdentifiers() ?? $this->getStableIdentifiers($collectionChange->getRemovedEntities()),
            );

            $sorted[] = [$this->getCollectionChangeSortKey($storageCollectionChange), $storageCollectionChange];
        }

        return new StorageDto($transaction, $entities, $this->sortByKey($sorted));
    }

    protected function getCollectionChangeSortKey(StorageCollectionChangeDto $collectionChange): string
    {
        return \json_encode([
            $collectionChange->getOwnerClass(),
            $collectionChange->getOwnerIdentifier(),
            $collectionChange->getField(),
            $collectionChange->getTargetClass(),
        ], \JSON_THROW_ON_ERROR);
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
