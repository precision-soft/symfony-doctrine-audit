<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto\Storage;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\CollectionChangeDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;

/**
 * @internal
 */
final class StorageDtoTest extends TestCase
{
    public function testGetTransaction(): void
    {
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, []);

        static::assertSame($transaction, $storageDto->getTransaction());
    }

    public function testGetEntitiesEmpty(): void
    {
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, []);

        static::assertSame([], $storageDto->getEntities());
    }

    public function testGetEntitiesWithData(): void
    {
        $transaction = new TransactionDto('admin');
        $fields = [new FieldDto('id', 'id', 'integer', 1)];
        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', $fields);
        $storageDto = new StorageDto($transaction, [$entity]);

        static::assertCount(1, $storageDto->getEntities());
        static::assertSame($entity, $storageDto->getEntities()[0]);
    }

    public function testGetEntitiesMultiple(): void
    {
        $transaction = new TransactionDto('admin');
        $userEntity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', []);
        $postEntity = new EntityDto(Operation::Delete, 'App\\Entity\\Post', 'post', []);
        $storageDto = new StorageDto($transaction, [$userEntity, $postEntity]);

        static::assertCount(2, $storageDto->getEntities());
        static::assertSame($userEntity, $storageDto->getEntities()[0]);
        static::assertSame($postEntity, $storageDto->getEntities()[1]);
    }

    public function testGetCollectionChanges(): void
    {
        $collectionChange = new CollectionChangeDto(
            'App\\Entity\\User',
            ['id' => 1],
            'roles',
            'App\\Entity\\Role',
            [['id' => 2]],
            [],
        );
        $storageDto = new StorageDto(new TransactionDto('admin'), [], [$collectionChange]);

        static::assertSame([$collectionChange], $storageDto->getCollectionChanges());
    }
}
