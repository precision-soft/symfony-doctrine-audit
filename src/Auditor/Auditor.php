<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Auditor;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto as AnnotationEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\AuditorDto;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\EntityDto as AuditorEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto as StorageEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Throwable;

final class Auditor
{
    use ThrowTrait;

    /** @var EntityDto[] */
    private ?array $auditedEntities;
    private ?AuditorDto $auditorDto;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly EntityManagerInterface $entityManager,
        private readonly array $storages,
        private readonly TransactionProviderInterface $transactionProvider,
        private readonly ?LoggerInterface $logger,
        private readonly AnnotationReadService $annotationReadService,
    ) {
        $this->auditedEntities = null;
        $this->auditorDto = null;
    }

    private function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function onFlush(OnFlushEventArgs $eventArgs): void
    {
        try {
            $this->auditedEntities ??= $this->annotationReadService->read($this->entityManager);

            $unitOfWork = $this->entityManager->getUnitOfWork();

            $entitiesToDelete = $this->filterAuditedEntities($unitOfWork->getScheduledEntityDeletions());

            $entitiesToInsert = $this->filterAuditedEntities($unitOfWork->getScheduledEntityInsertions());

            $entitiesToUpdate = $this->filterAuditedEntities($unitOfWork->getScheduledEntityUpdates());

            if (true === empty($entitiesToDelete) && true === empty($entitiesToInsert) && true === empty($entitiesToUpdate)) {
                return;
            }

            $changeSets = [];
            foreach ($entitiesToUpdate as $entity) {
                $changeSets[\spl_object_hash($entity)] = $unitOfWork->getEntityChangeSet($entity);
            }

            $this->auditorDto = new AuditorDto($entitiesToDelete, $entitiesToInsert, $entitiesToUpdate, $changeSets);

            $this->createAuditEntities($entitiesToDelete, Operation::Delete);
        } catch (Throwable $throwable) {
            $this->throw($throwable);
        }
    }

    public function postFlush(PostFlushEventArgs $eventArgs): void
    {
        try {
            if (null === $this->auditorDto) {
                return;
            }

            $this->createAuditEntities(
                $this->auditorDto->getEntitiesToInsert(),
                Operation::Insert,
            );

            $this->createAuditEntities(
                $this->auditorDto->getEntitiesToUpdate(),
                Operation::Update,
            );

            $storageDto = $this->createStorageDto();

            $this->save($storageDto);
            \gc_collect_cycles();
        } catch (Throwable $throwable) {
            $this->throw($throwable);
        } finally {
            $this->auditorDto = null;
        }
    }

    private function save(StorageDto $storageDto): void
    {
        /** @var StorageInterface $storage */
        foreach ($this->storages as $storage) {
            $storage->save($storageDto);
        }
    }

    private function createAuditEntities(array $entities, Operation $operation): void
    {
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
                $this->entityManager->getClassMetadata($entity::class),
                $entityData,
                $operation,
                $changeSet,
            );

            foreach ($entityDtos as $entityDto) {
                $this->auditorDto->addAuditEntity($entityDto);
            }
        }
    }

    private function createAuditorEntityDtos(
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

            if (false === (($association['type'] & ClassMetadata::TO_ONE) > 0 && true === $association['isOwningSide'])) {
                continue;
            }

            $associationData = $entityData[$field] ?? null;
            $relatedId = null;

            if (null !== $associationData && true === $unitOfWork->isInIdentityMap($associationData)) {
                $relatedId = $unitOfWork->getEntityIdentifier($associationData);
            }

            $targetClassMetadata = $this->entityManager->getClassMetadata($association['targetEntity']);

            foreach ($association['joinColumns'] as $joinColumn) {
                $sourceColumn = $joinColumn['name'];

                $targetFieldName = $targetClassMetadata->getFieldName($joinColumn['referencedColumnName']);
                $fieldType = $targetClassMetadata->getTypeOfField($targetFieldName);

                $relatedFieldValue = $relatedId[$targetFieldName] ?? null;

                $auditorEntityDto->addField(
                    new FieldDto($field, $sourceColumn, $fieldType, $relatedFieldValue),
                );
            }
        }

        foreach ($classMetadata->getFieldNames() as $field) {
            if (true === $classMetadata->isInheritanceTypeJoined() && true === $classMetadata->isInheritedField($field) && false === $classMetadata->isIdentifier($field)) {
                continue;
            }

            $columnName = $this->getColumnName($field, $classMetadata);

            $fieldMapping = $classMetadata->getFieldMapping($field);
            $fieldType = $fieldMapping['type'];
            $fieldValue = $entityData[$field] ?? null;
            $hasOldValue = true === \array_key_exists($field, $changeSet ?? []);
            $oldValue = true === $hasOldValue ? $changeSet[$field][0] : null;

            $auditorEntityDto->addField(
                new FieldDto($field, $columnName, $fieldType, $fieldValue, $oldValue, $hasOldValue),
            );
        }

        if (true === $classMetadata->isInheritanceTypeSingleTable()) {
            $auditorEntityDto->addField(
                new FieldDto(
                    $classMetadata->discriminatorColumn['fieldName'],
                    $classMetadata->discriminatorColumn['name'],
                    $classMetadata->discriminatorColumn['type'],
                    $classMetadata->discriminatorValue,
                ),
            );
        }

        if (true === $classMetadata->isInheritanceTypeJoined()) {
            $field = $classMetadata->discriminatorColumn['fieldName'];

            if (true === $classMetadata->isRootEntity()) {
                $auditorEntityDto->addField(
                    new FieldDto(
                        $field,
                        $classMetadata->discriminatorColumn['name'],
                        $classMetadata->discriminatorColumn['type'],
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

    private function getTableName(ClassMetadata $classMetadata): string
    {
        $quoteStrategy = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();

        return $quoteStrategy->getTableName($classMetadata, $platform);
    }

    private function getColumnName(string $field, ClassMetadata $classMetadata): string
    {
        $quoteStrategy = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();

        return $quoteStrategy->getColumnName($field, $classMetadata, $platform);
    }

    private function getOriginalEntityData(object $entity): array
    {
        $classMetadata = $this->entityManager->getClassMetadata($entity::class);
        $originalEntityData = $this->entityManager->getUnitOfWork()->getOriginalEntityData($entity);

        if (true === $classMetadata->isVersioned && null !== $classMetadata->versionField) {
            $versionField = $classMetadata->versionField;
            $originalEntityData[$versionField] = $classMetadata->reflFields[$versionField]->getValue($entity);
        }

        return $originalEntityData;
    }

    private function createStorageDto(): StorageDto
    {
        $transaction = $this->transactionProvider->getTransaction();

        $entities = \array_map(
            function (AuditorEntityDto $entityDto): ?StorageEntityDto {
                $fields = [];
                if (false === isset($this->auditedEntities[$entityDto->getClass()])) {
                    return null;
                }

                /** @var AnnotationEntityDto $annotationEntityDto */
                $annotationEntityDto = $this->auditedEntities[$entityDto->getClass()];

                foreach ($entityDto->getFields() as $fieldDto) {
                    if (\in_array($fieldDto->getName(), $annotationEntityDto->getIgnoredFields(), true)) {
                        continue;
                    }

                    if (\in_array($fieldDto->getName(), $this->configuration->getIgnoredFields(), true)) {
                        continue;
                    }

                    $fields[] = $fieldDto;
                }

                if (true === empty($fields)) {
                    return null;
                }

                return new StorageEntityDto(
                    $entityDto->getOperation(),
                    $entityDto->getClass(),
                    $entityDto->getTableName(),
                    $fields,
                );
            },
            $this->auditorDto->getAuditEntities(),
        );

        return new StorageDto($transaction, \array_filter($entities));
    }

    private function filterAuditedEntities(array $allEntities): array
    {
        $entities = [];

        foreach ($allEntities as $entity) {
            $hash = \spl_object_hash($entity);

            if (true === isset($entities[$hash])) {
                continue;
            }

            if (false === $this->hasAuditedEntity(AnnotationReadService::getEntityClass($entity))) {
                continue;
            }

            $entities[$hash] = $entity;
        }

        return \array_values($entities);
    }

    private function hasAuditedEntity(string $entityClass): bool
    {
        return true === isset($this->auditedEntities[$entityClass]);
    }
}
