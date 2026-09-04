<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Exception;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Contract\ExceptionInterface;
use Exception as BaseException;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Exception\StorageFailureException;

/**
 * @internal
 */
final class ExceptionTest extends TestCase
{
    public function testExtendsBaseException(): void
    {
        $exception = new Exception('test');

        static::assertInstanceOf(BaseException::class, $exception);
    }

    public function testMessageAndCode(): void
    {
        $exception = new Exception('error message', 42);

        static::assertSame('error message', $exception->getMessage());
        static::assertSame(42, $exception->getCode());
    }

    public function testPreviousException(): void
    {
        $previous = new Exception('original');
        $exception = new Exception('wrapped', 0, $previous);

        static::assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionImplementsExceptionInterface(): void
    {
        static::assertInstanceOf(ExceptionInterface::class, new Exception('test'));
    }

    public function testContextDefaultsToAnEmptyArray(): void
    {
        static::assertSame([], (new Exception('test'))->getContext());
        static::assertSame([], (new Exception('test', 0, null, null))->getContext());
    }

    public function testContextIsReadBackFromTheConstructor(): void
    {
        $exception = new Exception('test', 0, null, ['entityTableName' => 'user']);

        static::assertSame(['entityTableName' => 'user'], $exception->getContext());
    }

    public function testSetContextReplacesTheContextAndIsFluent(): void
    {
        $exception = new Exception('test', 0, null, ['first' => 1]);

        static::assertSame($exception, $exception->setContext(['second' => 2]));
        static::assertSame(['second' => 2], $exception->getContext());

        $exception->setContext(null);

        static::assertSame([], $exception->getContext());
    }

    public function testTheContextDoesNotLeakIntoTheMessageCodeOrPrevious(): void
    {
        $previous = new Exception('root cause');

        $exception = new Exception('test', 7, $previous, ['key' => 'value']);

        static::assertSame('test', $exception->getMessage());
        static::assertSame(7, $exception->getCode());
        static::assertSame($previous, $exception->getPrevious());
    }

    public function testStorageFailureExceptionCarriesTheFailingStoragesInItsContext(): void
    {
        $firstFailure = new Exception('first sink failed', 3);

        $storageFailureException = new StorageFailureException(
            [$firstFailure],
            true,
            ['failedStorages' => ['App\\Audit\\FileStorage'], 'storedPayload' => true],
        );

        static::assertInstanceOf(ExceptionInterface::class, $storageFailureException);
        static::assertSame(
            ['failedStorages' => ['App\\Audit\\FileStorage'], 'storedPayload' => true],
            $storageFailureException->getContext(),
        );

        static::assertSame('first sink failed', $storageFailureException->getMessage());
        static::assertSame(3, $storageFailureException->getCode());
        static::assertSame($firstFailure, $storageFailureException->getPrevious());
        static::assertSame([$firstFailure], $storageFailureException->getFailures());
        static::assertTrue($storageFailureException->hasStoredPayload());
    }

    public function testStorageFailureExceptionDefaultsToAnEmptyContext(): void
    {
        $storageFailureException = new StorageFailureException([new Exception('sink failed')], false);

        static::assertSame([], $storageFailureException->getContext());
    }

    public function testTheConstructorDefaultsToAnEmptyMessageZeroCodeAndNoPrevious(): void
    {
        $exception = new Exception();

        static::assertSame('', $exception->getMessage());
        static::assertSame(0, $exception->getCode());
        static::assertNull($exception->getPrevious());
        static::assertSame([], $exception->getContext());
    }
}
