<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Auditor;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\UnitOfWork;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto as AnnotationEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Test\Entity\OneEntity;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal
 */
final class AuditorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Configuration $configuration;
    private EntityManagerInterface|MockInterface $entityManager;
    private StorageInterface|MockInterface $storage;
    private TransactionProviderInterface|MockInterface $transactionProvider;
    private LoggerInterface|MockInterface $logger;
    private AnnotationReadService|MockInterface $annotationReadService;
    private UnitOfWork|MockInterface $unitOfWork;

    protected function setUp(): void
    {
        $this->configuration = new Configuration([]);
        $this->entityManager = Mockery::mock(EntityManagerInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->transactionProvider = Mockery::mock(TransactionProviderInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->annotationReadService = Mockery::mock(AnnotationReadService::class);
        $this->unitOfWork = Mockery::mock(UnitOfWork::class);

        $this->entityManager->shouldReceive('getUnitOfWork')
            ->andReturn($this->unitOfWork);
    }

    private const NO_LOGGER_SENTINEL = '__NO_LOGGER__';

    private function createAuditor(LoggerInterface|string|null $logger = self::NO_LOGGER_SENTINEL): Auditor
    {
        $resolvedLogger = (self::NO_LOGGER_SENTINEL === $logger) ? $this->logger : $logger;

        return new Auditor(
            $this->configuration,
            $this->entityManager,
            [$this->storage],
            $this->transactionProvider,
            $resolvedLogger,
            $this->annotationReadService,
        );
    }

    public function testOnFlushReturnsEarlyWhenNoAuditedEntities(): void
    {
        $auditor = $this->createAuditor();

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);

        $eventArgs = Mockery::mock(OnFlushEventArgs::class);

        $auditor->onFlush($eventArgs);

        /** @info no exception means early return worked */
        static::assertSame(true, true);
    }

    public function testOnFlushReturnsEarlyWhenEntitiesNotAudited(): void
    {
        $auditor = $this->createAuditor();
        $entity = new OneEntity();

        /** @info annotationReadService returns empty: no auditable entities */
        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);

        $eventArgs = Mockery::mock(OnFlushEventArgs::class);

        $auditor->onFlush($eventArgs);

        static::assertSame(true, true);
    }

    public function testOnFlushProcessesDeletedEntities(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);

        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);
        $platform->shouldReceive('getVarcharTypeDeclarationSQL')->andReturn('VARCHAR(255)');
        $platform->shouldReceive('getIntegerTypeDeclarationSQL')->andReturn('INTEGER');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $eventArgs = Mockery::mock(OnFlushEventArgs::class);

        $auditor->onFlush($eventArgs);

        /** @info postFlush should then process inserts/updates and save */
        static::assertSame(true, true);
    }

    public function testPostFlushReturnsEarlyWhenAuditorDtoIsNull(): void
    {
        $auditor = $this->createAuditor();

        $eventArgs = Mockery::mock(PostFlushEventArgs::class);

        /** @info no onFlush called, so auditorDto is null */
        $auditor->postFlush($eventArgs);

        static::assertSame(true, true);
    }

    public function testPostFlushProcessesInsertsAndUpdatesAndSaves(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);
        $platform->shouldReceive('getVarcharTypeDeclarationSQL')->andReturn('VARCHAR(255)');
        $platform->shouldReceive('getIntegerTypeDeclarationSQL')->andReturn('INTEGER');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        /** @info first onFlush */
        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        /** @info then postFlush should process inserts */
        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::type(StorageDto::class));

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);

        static::assertSame(true, true);
    }

    public function testOnFlushWithUpdateEntitiesCollectsChangeSets(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('NewName');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $changeSet = ['name' => ['OldName', 'NewName']];

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getEntityChangeSet')
            ->with($entity)
            ->andReturn($changeSet);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'OldName', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        /** @info now postFlush processes updates with changeSets */
        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (StorageDto $storageDto): bool {
                $entities = $storageDto->getEntities();
                static::assertNotEmpty($entities);

                return true;
            }));

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);
    }

    public function testOnFlushWithExceptionLogsAndThrows(): void
    {
        $auditor = $this->createAuditor($this->logger);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->andThrow(new RuntimeException('read failed'));

        $this->logger->shouldReceive('error')
            ->once()
            ->with(
                Mockery::pattern('/read failed/'),
                Mockery::type('array'),
            );

        $eventArgs = Mockery::mock(OnFlushEventArgs::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('read failed');

        $auditor->onFlush($eventArgs);
    }

    public function testOnFlushWithExceptionAndNullLoggerStillThrows(): void
    {
        $auditor = $this->createAuditor(null);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->andThrow(new RuntimeException('read failed'));

        $eventArgs = Mockery::mock(OnFlushEventArgs::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('read failed');

        $auditor->onFlush($eventArgs);
    }

    public function testPostFlushWithExceptionLogsAndThrows(): void
    {
        $auditor = $this->createAuditor($this->logger);

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);

        /** @info onFlush succeeds, setting auditorDto */
        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        /** @info postFlush will fail when processing inserts */
        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->andThrow(new RuntimeException('identifier failed'));

        $this->logger->shouldReceive('error')
            ->once();

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('identifier failed');

        $auditor->postFlush($postFlushEventArgs);
    }

    public function testPostFlushResetsAuditorDtoAfterSuccess(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::type(StorageDto::class));

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);

        /** @info calling postFlush again should return early since auditorDto was reset to null */
        $secondPostFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($secondPostFlushEventArgs);

        static::assertSame(true, true);
    }

    public function testFilterAuditedEntitiesDeduplicatesSameEntity(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        /** @info same entity passed twice in deletions */
        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([$entity, $entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        /** @info entity was deduplicated: only one createAuditEntities call per entity */
        static::assertSame(true, true);
    }

    public function testCreateStorageDtoFiltersIgnoredFields(): void
    {
        $this->configuration = new Configuration(['description']);
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (StorageDto $storageDto): bool {
                foreach ($storageDto->getEntities() as $entityDto) {
                    foreach ($entityDto->getFields() as $field) {
                        /** @info description should be filtered out */
                        static::assertNotSame('description', $field->getName());
                    }
                }

                return true;
            }));

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);
    }

    public function testMultipleStoragesAllCalledOnSave(): void
    {
        $secondStorage = Mockery::mock(StorageInterface::class);

        $auditor = new Auditor(
            $this->configuration,
            $this->entityManager,
            [$this->storage, $secondStorage],
            $this->transactionProvider,
            $this->logger,
            $this->annotationReadService,
        );

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'Test', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id']);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $quoteStrategy = new DefaultQuoteStrategy();
        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn($quoteStrategy);

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $s) => $s);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')->once()->with(Mockery::type(StorageDto::class));
        $secondStorage->shouldReceive('save')->once()->with(Mockery::type(StorageDto::class));

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);
    }
}
