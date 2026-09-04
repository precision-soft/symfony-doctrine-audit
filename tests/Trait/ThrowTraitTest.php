<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Trait;

use Mockery;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Test\Utility\ThrowTraitUser;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal
 */
final class ThrowTraitTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(
            LoggerInterface::class,
        );
    }

    public function testThrowWrapsExceptionInAuditException(): void
    {
        $throwTraitUser = $this->createThrowableClass(null);

        $original = new Exception('original message', 42);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('original message');
        $this->expectExceptionCode(42);

        $throwTraitUser->doThrow($original);
    }

    public function testThrowLogsErrorWhenLoggerPresent(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $throwTraitUser = $this->createThrowableClass($logger);

        $original = new Exception('log me', 100);

        $logger->shouldReceive('error')
            ->once()
            ->with(
                ThrowTraitUser::class . ': log me',
                Mockery::on(function (array $context): bool {
                    static::assertArrayHasKey('code', $context);
                    static::assertArrayHasKey('file', $context);
                    static::assertArrayHasKey('line', $context);
                    static::assertArrayHasKey('trace', $context);
                    static::assertSame(100, $context['code']);

                    return true;
                }),
            );

        $this->expectException(Exception::class);

        $throwTraitUser->doThrow($original);
    }

    public function testThrowDoesNotLogWhenLoggerIsNull(): void
    {
        $throwTraitUser = $this->createThrowableClass(null);

        $original = new Exception('no logging');

        try {
            $throwTraitUser->doThrow($original);
        } catch (Exception $exception) {
            static::assertSame('no logging', $exception->getMessage());
            static::assertSame($original, $exception->getPrevious());

            return;
        }

        static::fail('Expected Exception was not thrown');
    }

    public function testThrowPassesLogContextMergedWithExceptionInfo(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $throwTraitUser = $this->createThrowableClass($logger);

        $original = new Exception('context test');

        $logger->shouldReceive('error')
            ->once()
            ->with(
                Mockery::type('string'),
                Mockery::on(function (array $context): bool {
                    static::assertArrayHasKey('sql', $context);
                    static::assertSame('SELECT 1', $context['sql']);
                    static::assertArrayHasKey('code', $context);

                    return true;
                }),
            );

        $this->expectException(Exception::class);

        $throwTraitUser->doThrow($original, ['sql' => 'SELECT 1']);
    }

    public function testThrowPreservesOriginalExceptionAsPrevious(): void
    {
        $throwTraitUser = $this->createThrowableClass(null);

        $original = new Exception('bad arg');

        try {
            $throwTraitUser->doThrow($original);
        } catch (Exception $exception) {
            static::assertSame($original, $exception->getPrevious());

            return;
        }

        static::fail('Expected Exception was not thrown');
    }

    public function testThrowCarriesTheContextOfTheWrappedExceptionForward(): void
    {
        $throwTraitUser = $this->createThrowableClass(null);

        $original = new Exception('storage rejected the payload', 0, null, ['failedStorages' => ['SomeStorage']]);

        try {
            $throwTraitUser->doThrow($original);

            static::fail('Expected Exception was not thrown');
        } catch (Exception $exception) {
            static::assertSame(['failedStorages' => ['SomeStorage']], $exception->getContext());
        }
    }

    public function testThrowLeavesTheContextEmptyForAForeignThrowable(): void
    {
        $throwTraitUser = $this->createThrowableClass(null);

        try {
            $throwTraitUser->doThrow(new RuntimeException('not one of ours'));

            static::fail('Expected Exception was not thrown');
        } catch (Exception $exception) {
            static::assertSame([], $exception->getContext());
        }
    }

    private function createThrowableClass(?LoggerInterface $logger): ThrowTraitUser
    {
        return new ThrowTraitUser($logger);
    }
}
