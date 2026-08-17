<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\EventSubscriber;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
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
use PrecisionSoft\Doctrine\Audit\Test\Entity\OneEntity;
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

    private AnnotationReadServiceInterface&MockInterface $annotationReadService;
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

    public function testPostGenerateSchemaTableCarriesTheEntityTableNameInTheExceptionContext(): void
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

        try {
            $listener->postGenerateSchemaTable($eventArgs);

            static::fail('postGenerateSchemaTable was expected to throw');
        } catch (Exception $exception) {
            static::assertSame(['entityTableName' => 'fail_table'], $exception->getContext());

            static::assertSame('`fail_table` => `metadata failure`', $exception->getMessage());
        }
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
            ->with('id', 'integer', ['autoincrement' => true, 'notnull' => true])
            ->once()
            ->andReturn($idColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('username', Types::STRING, ['length' => 500])
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
            ->with('id', 'integer', ['autoincrement' => true, 'notnull' => true])
            ->andReturn($idColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('username', Types::STRING, ['length' => 500])
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
            ->with('id', 'integer', ['autoincrement' => true, 'notnull' => true])
            ->andReturn($idColumn);
        $transactionTable->shouldReceive('addColumn')
            ->with('username', Types::STRING, ['length' => 500])
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

    public function testPostGenerateSchemaTableBuildsTheAuditTableForAnAuditableEntity(): void
    {
        $listener = $this->createListener();

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id', 'id' => true]);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name', 'length' => 64]);
        $classMetadata->mapField(['fieldName' => 'description', 'type' => 'string', 'columnName' => 'description', 'length' => 64]);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->annotationReadService->shouldReceive('buildEntityDto')
            ->once()
            ->with($classMetadata)
            ->andReturn(new EntityDto(OneEntity::class, ['description']));

        $schema = new Schema();
        $entityTable = $schema->createTable('one_entity');
        $entityTable->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $entityTable->addColumn('name', Types::STRING, ['length' => 64]);
        $entityTable->addColumn('description', Types::STRING, ['length' => 64]);
        $entityTable->setPrimaryKey(['id']);

        $listener->postGenerateSchemaTable(
            new GenerateSchemaTableEventArgs($classMetadata, $schema, $entityTable),
        );

        static::assertTrue($schema->hasTable('one_entity'));

        $auditTable = $schema->getTable('one_entity');

        static::assertTrue($auditTable->hasColumn('id'));
        static::assertTrue($auditTable->hasColumn('name'));
        static::assertTrue($auditTable->hasColumn('audit_transaction_id'));
        static::assertTrue($auditTable->hasColumn('audit_operation'));

        static::assertFalse($auditTable->hasColumn('description'));

        $primaryKey = $auditTable->getPrimaryKey();
        static::assertNotNull($primaryKey);
        static::assertSame(['id', 'audit_transaction_id'], $primaryKey->getColumns());

        static::assertFalse($auditTable->getColumn('id')->getAutoincrement());
        static::assertFalse($auditTable->getColumn('name')->getNotnull());

        static::assertSame(Type::getType(Types::ENUM), $auditTable->getColumn('audit_operation')->getType());
        static::assertSame(['delete', 'insert', 'update'], $auditTable->getColumn('audit_operation')->getValues());

        static::assertTrue($auditTable->getColumn('audit_operation')->getNotnull());
    }

    public function testTheGeneratedAuditTableCanBeEmittedAsCreateTableSql(): void
    {
        $listener = $this->createListener();

        $classMetadata = new ClassMetadata(OneEntity::class);
        $classMetadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'columnName' => 'id', 'id' => true]);
        $classMetadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'name', 'length' => 64]);
        $classMetadata->table = ['name' => 'one_entity'];

        $this->annotationReadService->shouldReceive('buildEntityDto')
            ->once()
            ->with($classMetadata)
            ->andReturn(new EntityDto(OneEntity::class, []));

        $schema = new Schema();
        $entityTable = $schema->createTable('one_entity');
        $entityTable->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $entityTable->addColumn('name', Types::STRING, ['length' => 64]);
        $entityTable->setPrimaryKey(['id']);

        $listener->postGenerateSchemaTable(
            new GenerateSchemaTableEventArgs($classMetadata, $schema, $entityTable),
        );

        $createTableSql = \implode(' ', (new MySQLPlatform())->getCreateTableSQL($schema->getTable('one_entity')));

        static::assertStringContainsString("ENUM('delete', 'insert', 'update') NOT NULL", $createTableSql);
        static::assertStringContainsString('audit_transaction_id', $createTableSql);
    }
}
