<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Storage;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\CollectionChangeDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Storage\FailingFileStorage;

/**
 * @internal
 */
final class FileStorageTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = \sys_get_temp_dir() . '/audit_test_' . \uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (true === \is_dir($this->tmpFile)) {
            \rmdir($this->tmpFile);

            return;
        }

        if (true === \file_exists($this->tmpFile)) {
            \unlink($this->tmpFile);
        }
    }

    /* the assert is load-bearing: trim(false) is '' and every assertion below would pass on an empty string */
    private function readStorageFile(): string
    {
        $contents = \file_get_contents($this->tmpFile);

        static::assertNotFalse($contents, \sprintf('could not read `%s`', $this->tmpFile));

        return \trim($contents);
    }

    public function testSaveWritesJsonlLine(): void
    {
        $storage = new FileStorage($this->tmpFile, null);

        $fields = [
            new FieldDto('name', 'name', 'string', 'John'),
        ];
        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', $fields);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $storage->save($storageDto);

        static::assertFileExists($this->tmpFile);

        $line = $this->readStorageFile();
        $decoded = \json_decode($line, true);

        static::assertSame('admin', $decoded['username']);
        static::assertArrayHasKey('date', $decoded);
        static::assertCount(1, $decoded['entities']);
        static::assertSame('insert', $decoded['entities'][0]['operation']);
        static::assertSame('App\\Entity\\User', $decoded['entities'][0]['class']);
        static::assertSame('John', $decoded['entities'][0]['columns']['name']);
    }

    public function testSaveWithOldValueIncludesOldNew(): void
    {
        $storage = new FileStorage($this->tmpFile, null);

        $fields = [
            new FieldDto('name', 'name', 'string', 'John', 'Jane', true),
        ];
        $entity = new EntityDto(Operation::Update, 'App\\Entity\\User', 'user', $fields);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $storage->save($storageDto);

        $line = $this->readStorageFile();
        $decoded = \json_decode($line, true);

        $nameValue = $decoded['entities'][0]['columns']['name'];
        static::assertIsArray($nameValue);
        static::assertSame('Jane', $nameValue['old']);
        static::assertSame('John', $nameValue['new']);
    }

    public function testSaveWithExtrasIncludesExtras(): void
    {
        $storage = new FileStorage($this->tmpFile, null);

        $fields = [new FieldDto('id', 'id', 'integer', 1)];
        $entity = new EntityDto(Operation::Delete, 'App\\Entity\\User', 'user', $fields);
        $transaction = new TransactionDto('admin', ['ip' => '127.0.0.1']);
        $storageDto = new StorageDto($transaction, [$entity]);

        $storage->save($storageDto);

        $line = $this->readStorageFile();
        $decoded = \json_decode($line, true);

        static::assertArrayHasKey('extras', $decoded);
        static::assertSame('127.0.0.1', $decoded['extras']['ip']);
    }

    public function testSaveWritesCollectionChangesWithoutEntityRows(): void
    {
        $storage = new FileStorage($this->tmpFile, null);
        $collectionChange = new CollectionChangeDto(
            'App\\Entity\\User',
            ['id' => 10],
            'roles',
            'App\\Entity\\Role',
            [['id' => 2]],
            [['id' => 1]],
        );
        $storageDto = new StorageDto(new TransactionDto('admin'), [], [$collectionChange]);

        $storage->save($storageDto);

        $decoded = \json_decode($this->readStorageFile(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame([], $decoded['entities']);
        static::assertSame('App\\Entity\\User', $decoded['collections'][0]['owner_class']);
        static::assertSame(['id' => 10], $decoded['collections'][0]['owner_identifier']);
        static::assertSame('roles', $decoded['collections'][0]['field']);
        static::assertSame('App\\Entity\\Role', $decoded['collections'][0]['target_class']);
        static::assertSame([['id' => 2]], $decoded['collections'][0]['added']);
        static::assertSame([['id' => 1]], $decoded['collections'][0]['removed']);
    }

    public function testSaveSurfacesAnUnopenableFile(): void
    {
        \mkdir($this->tmpFile);

        $storage = new FileStorage($this->tmpFile, null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('could not open audit file');

        $storage->save($this->createStorageDto());
    }

    public function testSaveSurfacesAFileItCannotLock(): void
    {
        $storage = new FailingFileStorage($this->tmpFile, null, lock: false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('could not lock audit file');

        $storage->save($this->createStorageDto());
    }

    public function testSaveSurfacesAFailedWrite(): void
    {
        $storage = new FailingFileStorage($this->tmpFile, null, write: false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('could not write audit file');

        $storage->save($this->createStorageDto());
    }

    public function testSaveSurfacesAFailedFlush(): void
    {
        $storage = new FailingFileStorage($this->tmpFile, null, flush: false);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('could not flush audit file');

        $storage->save($this->createStorageDto());
    }

    public function testSaveWritesAPayloadLargerThanASingleWrite(): void
    {
        $storage = new FailingFileStorage($this->tmpFile, null, partialWriteSize: 8);

        $storage->save($this->createStorageDto());

        $decoded = \json_decode($this->readStorageFile(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('admin', $decoded['username']);
        static::assertSame('insert', $decoded['entities'][0]['operation']);
    }

    private function createStorageDto(): StorageDto
    {
        return new StorageDto(
            new TransactionDto('admin'),
            [new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [new FieldDto('id', 'id', 'integer', 1)])],
        );
    }

    public function testSaveAppendsMultipleLines(): void
    {
        $storage = new FileStorage($this->tmpFile, null);

        $entity = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', [
            new FieldDto('id', 'id', 'integer', 1),
        ]);
        $storageDto = new StorageDto(new TransactionDto('admin'), [$entity]);

        $storage->save($storageDto);
        $storage->save($storageDto);

        $lines = \array_filter(\explode(\PHP_EOL, $this->readStorageFile()));
        static::assertCount(2, $lines);
    }
}
