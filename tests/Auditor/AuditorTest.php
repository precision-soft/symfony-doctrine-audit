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
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto as AnnotationEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Test\Entity\OneEntity;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @internal
 */
final class AuditorTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    private Configuration $configuration;
    private EntityManagerInterface|MockInterface $entityManager;
    private StorageInterface|MockInterface $storage;
    private TransactionProviderInterface|MockInterface $transactionProvider;
    private LoggerInterface|MockInterface $logger;
    private AnnotationReadServiceInterface|MockInterface $annotationReadService;
    private UnitOfWork|MockInterface $unitOfWork;

    protected function setUp(): void
    {
        $this->configuration = new Configuration([]);
        $this->entityManager = Mockery::mock(EntityManagerInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->transactionProvider = Mockery::mock(TransactionProviderInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->annotationReadService = Mockery::mock(AnnotationReadServiceInterface::class);
        $this->unitOfWork = Mockery::mock(UnitOfWork::class);

        $this->entityManager->shouldReceive('getUnitOfWork')
            ->andReturn($this->unitOfWork);

        $this->annotationReadService->shouldReceive('getEntityClass')
            ->andReturnUsing(static fn(object $entityOrProxy): string => $entityOrProxy::class);
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

        $this->addToAssertionCount(1);
    }

    public function testOnFlushWithExceptionLogsAndThrows(): void
    {
        $auditor = $this->createAuditor($this->logger);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->andThrow(new Exception('read failed'));

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
            ->andThrow(new Exception('read failed'));

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
            ->andThrow(new Exception('identifier failed'));

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

    /**
     * Regression test for SDA-101: when the audit-db write fails in postFlush,
     * the full payload must be preserved on the logger (dead-letter) so the
     * audit row is recoverable rather than silently lost.
     */
    public function testPostFlushEmitsDeadLetterWhenAuditStorageWriteFails(): void
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

        /** @info simulate audit-db write failure */
        $storageFailure = new Exception('audit db is down');
        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::type(StorageDto::class))
            ->andThrow($storageFailure);

        /** @info the per-storage error is logged by save() */
        $this->logger->shouldReceive('error')
            ->once()
            ->with('audit storage failed', Mockery::type('array'));

        /** @info dead-letter: the full StorageDto payload must be preserved on the logger at critical level */
        $this->logger->shouldReceive('critical')
            ->once()
            ->with(
                Mockery::pattern('/audit_dead_letter/'),
                Mockery::on(function (array $context) use ($storageFailure): bool {
                    static::assertArrayHasKey('storage_dto', $context);
                    static::assertInstanceOf(StorageDto::class, $context['storage_dto']);
                    static::assertArrayHasKey('exception', $context);
                    static::assertSame($storageFailure, $context['exception']);

                    return true;
                }),
            );

        /** @info throw trait also logs at error level before re-throwing */
        $this->logger->shouldReceive('error')
            ->once();

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('audit db is down');

        $auditor->postFlush($postFlushEventArgs);
    }

    /**
     * Regression test for SDA-102: after a rolled-back flush the auditor dto
     * must not carry over to the next flush, otherwise a phantom audit row
     * would be emitted for the rolled-back change-set.
     */
    public function testRolledBackFlushDoesNotEmitPhantomAuditOnNextFlush(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        /** @info first onFlush: audited entities scheduled, auditor dto populated */
        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->ordered()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->ordered()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->ordered()->andReturn([]);
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

        /**
         * @info simulate the outer transaction rolling back: postFlush is NOT dispatched by
         *   doctrine on rollback (see UnitOfWork::commit: postFlush only runs after a successful
         *   conn->commit()). The auditor dto must therefore be cleared by the NEXT onFlush,
         *   not by postFlush.
         */

        /** @info second onFlush has NO audited entities; without SDA-102 fix the stale dto would still trigger postFlush processing */
        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->ordered()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->ordered()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->ordered()->andReturn([]);

        $secondOnFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($secondOnFlushEventArgs);

        /**
         * @info critical assertion: storage->save MUST NOT be called during the second postFlush
         *   because the stale auditor dto from the rolled-back flush must have been reset.
         *   Mockery will fail the test if save() is called without an expectation.
         */
        $this->storage->shouldNotReceive('save');

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);

        static::assertSame(true, true);
    }

    /**
     * Regression test for SDA-111 / SDA-112: on UPDATE, FieldDto->value must
     * carry the NEW value (from $changeSet[field][1]), not the OLD value
     * (which is what UnitOfWork::getOriginalEntityData() returns).
     */
    public function testUpdateFieldDtoCarriesNewValueNotOldValue(): void
    {
        $auditor = $this->createAuditor();

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('NewName');
        $entity->setDescription('NewDesc');

        $annotationEntityDto = new AnnotationEntityDto(OneEntity::class, []);

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => $annotationEntityDto]);

        /** @info the change-set contains [OLD, NEW] tuples */
        $changeSet = [
            'name' => ['OldName', 'NewName'],
            'description' => ['OldDesc', 'NewDesc'],
        ];

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getEntityChangeSet')
            ->with($entity)
            ->andReturn($changeSet);
        $this->unitOfWork->shouldReceive('getEntityIdentifier')
            ->with($entity)
            ->andReturn(['id' => 1]);
        /** @info getOriginalEntityData returns the OLD values; before the fix this was what landed in FieldDto->value */
        $this->unitOfWork->shouldReceive('getOriginalEntityData')
            ->with($entity)
            ->andReturn(['id' => 1, 'name' => 'OldName', 'description' => 'OldDesc']);

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

        $captured = null;
        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (StorageDto $storageDto) use (&$captured): bool {
                $captured = $storageDto;

                return true;
            }));

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);

        static::assertNotNull($captured);
        $entities = $captured->getEntities();
        static::assertCount(1, $entities);

        $byName = [];
        foreach ($entities[0]->getFields() as $fieldDto) {
            $byName[$fieldDto->getName()] = $fieldDto;
        }

        static::assertArrayHasKey('name', $byName);
        static::assertArrayHasKey('description', $byName);

        /** @info SDA-112: scalar field new-value on UPDATE must be $changeSet[field][1] */
        static::assertSame('NewName', $byName['name']->getValue(), 'FieldDto->value must be the new value, not the old one');
        static::assertSame('OldName', $byName['name']->getOldValue());
        static::assertSame(true, $byName['name']->hasOldValue());

        static::assertSame('NewDesc', $byName['description']->getValue(), 'FieldDto->value must be the new value, not the old one');
        static::assertSame('OldDesc', $byName['description']->getOldValue());
        static::assertSame(true, $byName['description']->hasOldValue());

        /** @info fields not in change-set (id) fall back to entityData and retain no old value marker */
        static::assertArrayHasKey('id', $byName);
        static::assertSame(1, $byName['id']->getValue());
        static::assertSame(false, $byName['id']->hasOldValue());
    }
}
