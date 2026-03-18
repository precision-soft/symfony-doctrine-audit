<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Storage;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;

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
        if (\file_exists($this->tmpFile)) {
            \unlink($this->tmpFile);
        }
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

        $line = \trim(\file_get_contents($this->tmpFile));
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
            new FieldDto('name', 'name', 'string', 'John', 'Jane'),
        ];
        $entity = new EntityDto(Operation::Update, 'App\\Entity\\User', 'user', $fields);
        $transaction = new TransactionDto('admin');
        $storageDto = new StorageDto($transaction, [$entity]);

        $storage->save($storageDto);

        $line = \trim(\file_get_contents($this->tmpFile));
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

        $line = \trim(\file_get_contents($this->tmpFile));
        $decoded = \json_decode($line, true);

        static::assertArrayHasKey('extras', $decoded);
        static::assertSame('127.0.0.1', $decoded['extras']['ip']);
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

        $lines = \array_filter(\explode(\PHP_EOL, \trim(\file_get_contents($this->tmpFile))));
        static::assertCount(2, $lines);
    }
}
