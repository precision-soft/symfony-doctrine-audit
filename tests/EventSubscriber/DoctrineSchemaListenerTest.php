<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\EventSubscriber;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfiguration;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\EventSubscriber\DoctrineSchemaListener;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as StorageConfiguration;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use stdClass;

/**
 * @internal
 */
final class DoctrineSchemaListenerTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    private AnnotationReadServiceInterface|MockInterface $annotationReadService;
    private AuditorConfiguration $auditorConfiguration;
    private StorageConfiguration $storageConfiguration;

    protected function setUp(): void
    {
        $this->annotationReadService = Mockery::mock(AnnotationReadServiceInterface::class);
        $this->auditorConfiguration = new AuditorConfiguration([]);
        $this->storageConfiguration = new StorageConfiguration([]);
    }

    private function createListener(): DoctrineSchemaListener
    {
        return new DoctrineSchemaListener(
            $this->annotationReadService,
            $this->auditorConfiguration,
            $this->storageConfiguration,
        );
    }

    public function testPostGenerateSchemaTableDropsTableWhenNotAuditable(): void
    {
        $listener = $this->createListener();

        $classMetadata = new ClassMetadata(stdClass::class);

        $this->annotationReadService->shouldReceive('buildEntityDto')
            ->once()
            ->with($classMetadata)
            ->andReturnNull();

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('dropTable')
            ->once()
            ->with('some_table');

        $entityTable = Mockery::mock(Table::class);
        $entityTable->shouldReceive('getName')
            ->andReturn('some_table');

        $eventArgs = Mockery::mock(GenerateSchemaTableEventArgs::class);
        $eventArgs->shouldReceive('getClassMetadata')->once()->andReturn($classMetadata);
        $eventArgs->shouldReceive('getSchema')->once()->andReturn($schema);
        $eventArgs->shouldReceive('getClassTable')->once()->andReturn($entityTable);

        $listener->postGenerateSchemaTable($eventArgs);
    }

    public function testPostGenerateSchemaTableWrapsExceptionInAuditException(): void
    {
        $listener = $this->createListener();

        $classMetadata = new ClassMetadata(stdClass::class);

        $this->annotationReadService->shouldReceive('buildEntityDto')
            ->once()
            ->andThrow(new Exception('metadata failure'));

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('dropTable')->once();

        $entityTable = Mockery::mock(Table::class);
        $entityTable->shouldReceive('getName')->andReturn('fail_table');

        $eventArgs = Mockery::mock(GenerateSchemaTableEventArgs::class);
        $eventArgs->shouldReceive('getClassMetadata')->once()->andReturn($classMetadata);
        $eventArgs->shouldReceive('getSchema')->once()->andReturn($schema);
        $eventArgs->shouldReceive('getClassTable')->once()->andReturn($entityTable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('`fail_table` => `metadata failure`');

        $listener->postGenerateSchemaTable($eventArgs);
    }

    public function testPostGenerateSchemaTableThrowsWhenEntityTableHasNoPrimaryKey(): void
    {
        $listener = $this->createListener();

        $classMetadata = new ClassMetadata(stdClass::class);

        $entityDto = Mockery::mock(EntityDto::class);

        $this->annotationReadService->shouldReceive('buildEntityDto')
            ->once()
            ->with($classMetadata)
            ->andReturn($entityDto);

        $column = Mockery::mock(Column::class);
        $column->shouldReceive('getName')->andReturn('unmapped_column');

        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getColumns')->andReturn([$column]);
        $table->shouldReceive('addColumn')->andReturn(Mockery::mock(Column::class));

        $entityTable = Mockery::mock(Table::class);
        $entityTable->shouldReceive('getName')->andReturn('no_pk_table');
        /** @info the table has no primary key; in production (assertions off) this previously crashed with a "method on null" fatal */
        $entityTable->shouldReceive('getPrimaryKey')->once()->andReturnNull();

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('getTable')->with('no_pk_table')->andReturn($table);
        $schema->shouldReceive('dropTable')->once()->with('no_pk_table');

        $eventArgs = Mockery::mock(GenerateSchemaTableEventArgs::class);
        $eventArgs->shouldReceive('getClassMetadata')->andReturn($classMetadata);
        $eventArgs->shouldReceive('getSchema')->andReturn($schema);
        $eventArgs->shouldReceive('getClassTable')->andReturn($entityTable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/has no primary key/');

        $listener->postGenerateSchemaTable($eventArgs);
    }

    public function testPostGenerateSchemaCreatesTransactionTable(): void
    {
        $listener = $this->createListener();

        $idColumn = Mockery::mock(Column::class);
        $usernameColumn = Mockery::mock(Column::class);
        $usernameColumn->shouldReceive('setNotnull')->with(false)->once()->andReturnSelf();
        $createdColumn = Mockery::mock(Column::class);

        $transactionTable = Mockery::mock(Table::class);
        $transactionTable->shouldReceive('addColumn')
            ->with('id', 'integer', Mockery::type('array'))
            ->once()
            ->andReturn($idColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('username', Types::STRING, Mockery::type('array'))
            ->once()
            ->andReturn($usernameColumn);
        $extrasColumn = Mockery::mock(Column::class);
        $extrasColumn->shouldReceive('setNotnull')->with(false)->once()->andReturnSelf();

        $transactionTable->shouldReceive('addColumn')
            ->with('created', Types::DATETIME_IMMUTABLE)
            ->once()
            ->andReturn($createdColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('extras', Types::TEXT)
            ->once()
            ->andReturn($extrasColumn);
        $transactionTable->shouldReceive('setPrimaryKey')
            ->once()
            ->with(['id']);

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('createTable')
            ->once()
            ->with('audit_transaction')
            ->andReturn($transactionTable);
        $schema->shouldReceive('getTables')
            ->once()
            ->andReturn([]);

        $entityManager = Mockery::mock(EntityManagerInterface::class);

        $eventArgs = Mockery::mock(GenerateSchemaEventArgs::class);
        $eventArgs->shouldReceive('getSchema')->once()->andReturn($schema);

        $listener->postGenerateSchema($eventArgs);
    }

    public function testPostGenerateSchemaAddsForeignKeysToOtherTables(): void
    {
        $listener = $this->createListener();

        $idColumn = Mockery::mock(Column::class);
        $usernameColumn = Mockery::mock(Column::class);
        $usernameColumn->shouldReceive('setNotnull')->with(false)->andReturnSelf();
        $createdColumn = Mockery::mock(Column::class);

        $transactionTable = Mockery::mock(Table::class);
        $transactionTable->shouldReceive('addColumn')
            ->with('id', 'integer', Mockery::type('array'))
            ->andReturn($idColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('username', Types::STRING, Mockery::type('array'))
            ->andReturn($usernameColumn);
        $extrasColumn = Mockery::mock(Column::class);
        $extrasColumn->shouldReceive('setNotnull')->with(false)->andReturnSelf();

        $transactionTable->shouldReceive('addColumn')
            ->with('created', Types::DATETIME_IMMUTABLE)
            ->andReturn($createdColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('extras', Types::TEXT)
            ->andReturn($extrasColumn);
        $transactionTable->shouldReceive('setPrimaryKey')
            ->with(['id']);

        $otherTable = Mockery::mock(Table::class);
        $otherTable->shouldReceive('getName')->andReturn('some_audit_table');
        $otherTable->shouldReceive('addForeignKeyConstraint')
            ->once()
            ->with(
                'audit_transaction',
                ['audit_transaction_id'],
                ['id'],
                ['onDelete' => 'RESTRICT'],
            );

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('createTable')
            ->with('audit_transaction')
            ->andReturn($transactionTable);
        $schema->shouldReceive('getTables')
            ->andReturn([$otherTable]);

        $eventArgs = Mockery::mock(GenerateSchemaEventArgs::class);
        $eventArgs->shouldReceive('getSchema')->andReturn($schema);

        $listener->postGenerateSchema($eventArgs);
    }

    public function testPostGenerateSchemaSkipsForeignKeyForTransactionTable(): void
    {
        $listener = $this->createListener();

        $idColumn = Mockery::mock(Column::class);
        $usernameColumn = Mockery::mock(Column::class);
        $usernameColumn->shouldReceive('setNotnull')->with(false)->andReturnSelf();
        $createdColumn = Mockery::mock(Column::class);

        $transactionTable = Mockery::mock(Table::class);
        $transactionTable->shouldReceive('addColumn')
            ->with('id', 'integer', Mockery::type('array'))
            ->andReturn($idColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('username', Types::STRING, Mockery::type('array'))
            ->andReturn($usernameColumn);
        $extrasColumn = Mockery::mock(Column::class);
        $extrasColumn->shouldReceive('setNotnull')->with(false)->andReturnSelf();

        $transactionTable->shouldReceive('addColumn')
            ->with('created', Types::DATETIME_IMMUTABLE)
            ->andReturn($createdColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('extras', Types::TEXT)
            ->andReturn($extrasColumn);
        $transactionTable->shouldReceive('setPrimaryKey')
            ->with(['id']);

        /** @info the transaction table itself should not get a foreign key */
        $txnTableInList = Mockery::mock(Table::class);
        $txnTableInList->shouldReceive('getName')->andReturn('audit_transaction');
        $txnTableInList->shouldNotReceive('addForeignKeyConstraint');

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('createTable')
            ->with('audit_transaction')
            ->andReturn($transactionTable);
        $schema->shouldReceive('getTables')
            ->andReturn([$txnTableInList]);

        $eventArgs = Mockery::mock(GenerateSchemaEventArgs::class);
        $eventArgs->shouldReceive('getSchema')->andReturn($schema);

        $listener->postGenerateSchema($eventArgs);
    }

    public function testPostGenerateSchemaWrapsException(): void
    {
        $listener = $this->createListener();

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('createTable')
            ->andThrow(new Exception('create failed'));

        $eventArgs = Mockery::mock(GenerateSchemaEventArgs::class);
        $eventArgs->shouldReceive('getSchema')->andReturn($schema);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('`audit_transaction` => `create failed`');

        $listener->postGenerateSchema($eventArgs);
    }
}
