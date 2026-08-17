<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Auditor;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto as AnnotationEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Exception\StorageFailureException;
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
    private const REFL_FIELDS_DEPRECATION_LINK = 'https://github.com/doctrine/orm/pull/11659';

    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    private Configuration $configuration;
    private EntityManagerInterface&MockInterface $entityManager;
    private StorageInterface&MockInterface $storage;
    private TransactionProviderInterface&MockInterface $transactionProvider;
    private LoggerInterface&MockInterface $logger;
    private AnnotationReadServiceInterface&MockInterface $annotationReadService;
    private UnitOfWork&MockInterface $unitOfWork;

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

    private function createAuditor(?LoggerInterface $logger = null): Auditor
    {
        return $this->buildAuditor($logger ?? $this->logger);
    }

    private function createAuditorWithoutLogger(): Auditor
    {
        return $this->buildAuditor(null);
    }

    /**
     * @param StorageInterface[] $storages
     */
    private function createAuditorFlushingOneInsert(array $storages, OneEntity $entity): Auditor
    {
        $auditor = new Auditor(
            $this->configuration,
            $this->entityManager,
            $storages,
            $this->transactionProvider,
            $this->logger,
            $this->annotationReadService,
        );

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([OneEntity::class => new AnnotationEntityDto(OneEntity::class, [])]);

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

        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn(new DefaultQuoteStrategy());

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $identifier) => $identifier);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $auditor->onFlush(Mockery::mock(OnFlushEventArgs::class));

        return $auditor;
    }

    private function buildAuditor(?LoggerInterface $logger): Auditor
    {
        return new Auditor(
            $this->configuration,
            $this->entityManager,
            [$this->storage],
            $this->transactionProvider,
            $logger,
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

        $this->storage->shouldNotReceive('save');

        $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));
    }

    public function testOnFlushReturnsEarlyWhenEntitiesNotAudited(): void
    {
        $auditor = $this->createAuditor();
        $entity = new OneEntity();

        $this->annotationReadService->shouldReceive('read')
            ->once()
            ->with($this->entityManager)
            ->andReturn([]);

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->andReturn([$entity]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->andReturn([]);

        $eventArgs = Mockery::mock(OnFlushEventArgs::class);

        $auditor->onFlush($eventArgs);

        $this->storage->shouldNotReceive('save');

        $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));
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

        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (StorageDto $storageDto): bool {
                static::assertCount(1, $storageDto->getEntities());
                static::assertSame(Operation::Delete, $storageDto->getEntities()[0]->getOperation());
                static::assertSame('one_entity', $storageDto->getEntities()[0]->getTableName());

                return true;
            }));

        $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));
    }

    public function testPostFlushReturnsEarlyWhenAuditorDtoIsNull(): void
    {
        $auditor = $this->createAuditor();

        $eventArgs = Mockery::mock(PostFlushEventArgs::class);

        $this->storage->shouldNotReceive('save');
        $this->transactionProvider->shouldNotReceive('getTransaction');

        $auditor->postFlush($eventArgs);
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
        $auditor = $this->createAuditorWithoutLogger();

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

        $onFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($onFlushEventArgs);

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

        /* the second postFlush is proved by the once() on getTransaction and save above, which a second pass would violate */
        $secondPostFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($secondPostFlushEventArgs);
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

        $this->transactionProvider->shouldReceive('getTransaction')
            ->once()
            ->andReturn(new TransactionDto('admin'));

        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (StorageDto $storageDto): bool {
                static::assertCount(1, $storageDto->getEntities());

                return true;
            }));

        $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));
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

        $storageFailure = new Exception('audit db is down');
        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::type(StorageDto::class))
            ->andThrow($storageFailure);

        $this->logger->shouldReceive('error')
            ->once()
            ->with('audit storage failed', Mockery::type('array'));

        $this->logger->shouldReceive('critical')
            ->once()
            ->with(
                Mockery::pattern('/audit_dead_letter/'),
                Mockery::on(function (array $context) use ($storageFailure): bool {
                    static::assertArrayHasKey('storage_dto', $context);
                    static::assertInstanceOf(StorageDto::class, $context['storage_dto']);
                    static::assertArrayHasKey('exception', $context);

                    $exception = $context['exception'];
                    static::assertInstanceOf(StorageFailureException::class, $exception);
                    static::assertSame(false, $exception->hasStoredPayload());
                    static::assertSame([$storageFailure], $exception->getFailures());
                    static::assertSame($storageFailure, $exception->getPrevious());

                    return true;
                }),
            );

        $this->logger->shouldReceive('error')
            ->once();

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('audit db is down');

        $auditor->postFlush($postFlushEventArgs);
    }

    public function testRolledBackFlushDoesNotEmitPhantomAuditOnNextFlush(): void
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

        $this->unitOfWork->shouldReceive('getScheduledEntityDeletions')->once()->ordered()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityInsertions')->once()->ordered()->andReturn([]);
        $this->unitOfWork->shouldReceive('getScheduledEntityUpdates')->once()->ordered()->andReturn([]);

        $secondOnFlushEventArgs = Mockery::mock(OnFlushEventArgs::class);
        $auditor->onFlush($secondOnFlushEventArgs);

        /* save() has no expectation for a second pass, so mockery fails the test if the stale dto reaches it */
        $this->storage->shouldNotReceive('save');

        $postFlushEventArgs = Mockery::mock(PostFlushEventArgs::class);
        $auditor->postFlush($postFlushEventArgs);
    }

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

        static::assertSame('NewName', $byName['name']->getValue(), 'FieldDto->value must be the new value, not the old one');
        static::assertSame('OldName', $byName['name']->getOldValue());
        static::assertSame(true, $byName['name']->hasOldValue());

        static::assertSame('NewDesc', $byName['description']->getValue(), 'FieldDto->value must be the new value, not the old one');
        static::assertSame('OldDesc', $byName['description']->getOldValue());
        static::assertSame(true, $byName['description']->hasOldValue());

        static::assertArrayHasKey('id', $byName);
        static::assertSame(1, $byName['id']->getValue());
        static::assertSame(false, $byName['id']->hasOldValue());
    }

    public function testVersionFieldIsReadWithoutTheDeprecatedReflFields(): void
    {
        Deprecation::enableTrackingDeprecations();

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
            ->andReturn(['id' => 1, 'name' => 'Stale', 'description' => 'Desc']);

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->initializeReflection(new RuntimeReflectionService());
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id', 'id' => true]);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name']);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description']);
        $classMetadata->table = ['name' => 'one_entity'];
        $classMetadata->isVersioned = true;
        $classMetadata->versionField = 'name';
        $classMetadata->wakeupReflection(new RuntimeReflectionService());

        $this->entityManager->shouldReceive('getClassMetadata')
            ->with(OneEntity::class)
            ->andReturn($classMetadata);

        $ormConfiguration = Mockery::mock(OrmConfiguration::class);
        $ormConfiguration->shouldReceive('getQuoteStrategy')->andReturn(new DefaultQuoteStrategy());

        $platform = Mockery::mock(AbstractPlatform::class);
        $platform->shouldReceive('quoteIdentifier')->andReturnUsing(fn(string $identifier) => $identifier);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')->andReturn($platform);

        $this->entityManager->shouldReceive('getConfiguration')->andReturn($ormConfiguration);
        $this->entityManager->shouldReceive('getConnection')->andReturn($connection);

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

        $before = Deprecation::getTriggeredDeprecations()[static::REFL_FIELDS_DEPRECATION_LINK] ?? 0;

        $auditor->onFlush(Mockery::mock(OnFlushEventArgs::class));
        $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));

        $after = Deprecation::getTriggeredDeprecations()[static::REFL_FIELDS_DEPRECATION_LINK] ?? 0;

        static::assertNotNull($captured);

        $fieldsByName = [];
        foreach ($captured->getEntities()[0]->getFields() as $fieldDto) {
            $fieldsByName[$fieldDto->getName()] = $fieldDto;
        }

        static::assertSame('Test', $fieldsByName['name']->getValue());
        static::assertSame($before, $after, 'auditing a versioned entity must not touch the deprecated ClassMetadata::$reflFields');
    }

    public function testPartialStorageFailureDoesNotEmitTheDeadLetter(): void
    {
        $secondStorage = Mockery::mock(StorageInterface::class);

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $auditor = $this->createAuditorFlushingOneInsert([$this->storage, $secondStorage], $entity);

        $storageFailure = new Exception('audit db is down');
        $this->storage->shouldReceive('save')
            ->once()
            ->with(Mockery::type(StorageDto::class))
            ->andThrow($storageFailure);

        $secondStorage->shouldReceive('save')
            ->once()
            ->with(Mockery::type(StorageDto::class));

        $this->logger->shouldReceive('error')->atLeast()->once();

        $this->logger->shouldNotReceive('critical');

        try {
            $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));

            static::fail('a rejecting storage must surface as an exception');
        } catch (Exception $exception) {
            static::assertSame('audit db is down', $exception->getMessage());

            $storageFailureException = $exception->getPrevious();

            static::assertInstanceOf(StorageFailureException::class, $storageFailureException);
            static::assertTrue($storageFailureException->hasStoredPayload());

            $expectedContext = [
                'failedStorages' => [$this->storage::class],
                'storedPayload' => true,
            ];

            static::assertSame($expectedContext, $storageFailureException->getContext());
            static::assertSame($expectedContext, $exception->getContext());
        }
    }

    public function testTotalStorageFailureStillEmitsTheDeadLetter(): void
    {
        $secondStorage = Mockery::mock(StorageInterface::class);

        $entity = new OneEntity();
        $entity->setId(1);
        $entity->setName('Test');
        $entity->setDescription('Desc');

        $auditor = $this->createAuditorFlushingOneInsert([$this->storage, $secondStorage], $entity);

        $this->storage->shouldReceive('save')->once()->andThrow(new Exception('first sink down'));
        $secondStorage->shouldReceive('save')->once()->andThrow(new Exception('second sink down'));

        $this->logger->shouldReceive('error')->atLeast()->once();

        $this->logger->shouldReceive('critical')
            ->once()
            ->with(
                Mockery::pattern('/audit_dead_letter/'),
                Mockery::on(function (array $context): bool {
                    static::assertInstanceOf(StorageDto::class, $context['storage_dto']);

                    return true;
                }),
            );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('first sink down');

        $auditor->postFlush(Mockery::mock(PostFlushEventArgs::class));
    }
}
