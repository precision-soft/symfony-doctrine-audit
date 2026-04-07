<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Trait;

use InvalidArgumentException;
use Mockery;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
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

    private function createThrowableClass(?LoggerInterface $logger): object
    {
        return new class ($logger) {
            use ThrowTrait;

            public function __construct(private readonly ?LoggerInterface $logger) {}

            private function getLogger(): ?LoggerInterface
            {
                return $this->logger;
            }

            public function doThrow(\Throwable $t, array $logContext = []): void
            {
                $this->throw($t, $logContext);
            }
        };
    }

    public function testThrowWrapsExceptionInAuditException(): void
    {
        $throwTraitUser = $this->createThrowableClass(null);

        $original = new RuntimeException('original message', 42);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('original message');
        $this->expectExceptionCode(42);

        $throwTraitUser->doThrow($original);
    }

    public function testThrowLogsErrorWhenLoggerPresent(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $throwTraitUser = $this->createThrowableClass($logger);

        $original = new RuntimeException('log me', 100);

        $logger->shouldReceive('error')
            ->once()
            ->with(
                Mockery::pattern('/log me/'),
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

        $original = new RuntimeException('no logging');

        try {
            $throwTraitUser->doThrow($original);
        } catch (Exception $e) {
            static::assertSame('no logging', $e->getMessage());
            static::assertSame($original, $e->getPrevious());

            return;
        }

        static::fail('Expected Exception was not thrown');
    }

    public function testThrowPassesLogContextMergedWithExceptionInfo(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $throwTraitUser = $this->createThrowableClass($logger);

        $original = new RuntimeException('context test');

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

        $original = new InvalidArgumentException('bad arg');

        try {
            $throwTraitUser->doThrow($original);
        } catch (Exception $e) {
            static::assertSame($original, $e->getPrevious());

            return;
        }

        static::fail('Expected Exception was not thrown');
    }
}
