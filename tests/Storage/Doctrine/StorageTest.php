<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Storage\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Storage;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal
 */
final class StorageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private EntityManagerInterface|MockInterface $entityManager;
    private Configuration $configuration;
    private Connection|MockInterface $connection;
    private AbstractPlatform|MockInterface $platform;

    protected function setUp(): void
    {
        $this->entityManager = Mockery::mock(EntityManagerInterface::class);
        $this->configuration = new Configuration([]);
        $this->connection = Mockery::mock(Connection::class);
        $this->platform = Mockery::mock(AbstractPlatform::class);

        $this->entityManager->shouldReceive('getConnection')
            ->andReturn($this->connection);
        $this->connection->shouldReceive('getDatabasePlatform')
            ->andReturn($this->platform);
        $this->connection->shouldReceive('beginTransaction')->byDefault();
        $this->connection->shouldReceive('commit')->byDefault();
        $this->connection->shouldReceive('rollBack')->byDefault();
    }

    private function createStorage(?LoggerInterface $logger = null): Storage
    {
        return new Storage(
            $this->entityManager,
            $this->configuration,
            $logger,
        );
    }

    public function testSaveReturnsEarlyWhenNoEntities(): void
    {
        $storage = $this->createStorage();

        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, []);

        /** @info should not call insert on connection */
        $this->connection->shouldNotReceive('insert');

        $storage->save($storageDto);

        static::assertSame(true, true);
    }

    public function testSaveInsertsTransactionAndEntity(): void
    {
        $storage = $this->createStorage();

        $fields = [
            new FieldDto('name', 'name', 'string', 'John'),
            new FieldDto('id', 'id', 'integer', 1),
        ];
        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', $fields);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        /** @info getTransactionId inserts into transaction table */
        $this->connection->shouldReceive('insert')
            ->once()
            ->with(
                'audit_transaction',
                Mockery::type('array'),
                Mockery::type('array'),
            );

        $this->connection->shouldReceive('lastInsertId')
            ->once()
            ->andReturn('42');

        $this->platform->shouldReceive('quoteIdentifier')
            ->andReturnUsing(fn(string $s) => '"' . $s . '"');

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->with(
                Mockery::on(function (string $sql): bool {
                    static::assertStringContainsString('INSERT INTO "user"', $sql);
                    static::assertStringContainsString('"audit_transaction_id"', $sql);
                    static::assertStringContainsString('"audit_operation"', $sql);
                    static::assertStringContainsString('"name"', $sql);
                    static::assertStringContainsString('"id"', $sql);

                    return true;
                }),
                Mockery::on(function (array $values): bool {
                    static::assertSame(42, $values[0]);
                    static::assertSame('insert', $values[1]);
                    static::assertSame('John', $values[2]);
                    static::assertSame(1, $values[3]);

                    return true;
                }),
                Mockery::type('array'),
            );

        $storage->save($storageDto);
    }

    public function testSaveMultipleEntities(): void
    {
        $storage = $this->createStorage();

        $userEntity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [
            new FieldDto('id', 'id', 'integer', 1),
        ]);
        $postEntity = new EntityDto(Operation::Delete, 'App\\Entity\\Post', 'post', [
            new FieldDto('id', 'id', 'integer', 2),
        ]);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$userEntity, $postEntity]);

        $this->connection->shouldReceive('insert')->once();
        $this->connection->shouldReceive('lastInsertId')->once()->andReturn('10');

        $this->platform->shouldReceive('quoteIdentifier')
            ->andReturnUsing(fn(string $s) => '"' . $s . '"');

        /** @info should call executeStatement twice, once per entity */
        $this->connection->shouldReceive('executeStatement')
            ->twice()
            ->with(
                Mockery::type('string'),
                Mockery::type('array'),
                Mockery::type('array'),
            );

        $storage->save($storageDto);
    }

    public function testSaveThrowsExceptionWhenLastInsertIdIsZeroString(): void
    {
        $storage = $this->createStorage();

        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [
            new FieldDto('id', 'id', 'integer', 1),
        ]);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $this->connection->shouldReceive('insert')->once();
        $this->connection->shouldReceive('lastInsertId')->once()->andReturn('0');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('failed to retrieve last insert id');

        $storage->save($storageDto);
    }

    public function testSaveThrowsExceptionWhenLastInsertIdIsZeroInt(): void
    {
        $storage = $this->createStorage();

        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [
            new FieldDto('id', 'id', 'integer', 1),
        ]);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $this->connection->shouldReceive('insert')->once();
        $this->connection->shouldReceive('lastInsertId')->once()->andReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('failed to retrieve last insert id');

        $storage->save($storageDto);
    }

    public function testSaveEntityExecuteStatementFailsWrapsException(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $storage = $this->createStorage($logger);

        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [
            new FieldDto('id', 'id', 'integer', 1),
        ]);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $this->connection->shouldReceive('insert')->once();
        $this->connection->shouldReceive('lastInsertId')->once()->andReturn('1');

        $this->platform->shouldReceive('quoteIdentifier')
            ->andReturnUsing(fn(string $s) => '"' . $s . '"');

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->andThrow(new RuntimeException('SQL error'));

        $logger->shouldReceive('error')
            ->once()
            ->with(
                Mockery::pattern('/SQL error/'),
                Mockery::on(function (array $context): bool {
                    static::assertArrayHasKey('sql', $context);

                    return true;
                }),
            );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SQL error');

        $storage->save($storageDto);
    }

    public function testSaveWithCustomConfiguration(): void
    {
        $this->configuration = new Configuration([
            'transaction_table_name' => 'custom_txn',
            'transaction_id_column_name' => 'custom_txn_id',
            'transaction_id_column_type' => 'bigint',
            'operation_column_name' => 'custom_op',
        ]);
        $storage = $this->createStorage();

        $entity = new EntityDto(Operation::Update, 'App\\Entity\\User', 'user', [
            new FieldDto('name', 'name', 'string', 'Jane'),
        ]);
        $transaction = new TransactionDto('editor');
        $storageDto = new StorageDto($transaction, [$entity]);

        $this->connection->shouldReceive('insert')
            ->once()
            ->with('custom_txn', Mockery::type('array'), Mockery::type('array'));

        $this->connection->shouldReceive('lastInsertId')->once()->andReturn('5');

        $this->platform->shouldReceive('quoteIdentifier')
            ->andReturnUsing(fn(string $s) => '"' . $s . '"');

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->with(
                Mockery::on(function (string $sql): bool {
                    static::assertStringContainsString('"custom_txn_id"', $sql);
                    static::assertStringContainsString('"custom_op"', $sql);

                    return true;
                }),
                Mockery::type('array'),
                Mockery::on(function (array $types): bool {
                    static::assertSame('bigint', $types[0]);

                    return true;
                }),
            );

        $storage->save($storageDto);
    }

    public function testSaveWithNullLoggerDoesNotLogOnError(): void
    {
        $storage = $this->createStorage(null);

        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [
            new FieldDto('id', 'id', 'integer', 1),
        ]);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $this->connection->shouldReceive('insert')->once();
        $this->connection->shouldReceive('lastInsertId')->once()->andReturn('1');

        $this->platform->shouldReceive('quoteIdentifier')
            ->andReturnUsing(fn(string $s) => '"' . $s . '"');

        $this->connection->shouldReceive('executeStatement')
            ->once()
            ->andThrow(new RuntimeException('SQL error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SQL error');

        $storage->save($storageDto);
    }
}
