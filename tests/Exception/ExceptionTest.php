<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Exception;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use RuntimeException;

/**
 * @internal
 */
final class ExceptionTest extends TestCase
{
    public function testExtendsBaseException(): void
    {
        $exception = new Exception('test');

        static::assertInstanceOf(\Exception::class, $exception);
    }

    public function testMessageAndCode(): void
    {
        $exception = new Exception('error message', 42);

        static::assertSame('error message', $exception->getMessage());
        static::assertSame(42, $exception->getCode());
    }

    public function testPreviousException(): void
    {
        $previous = new RuntimeException('original');
        $exception = new Exception('wrapped', 0, $previous);

        static::assertSame($previous, $exception->getPrevious());
    }
}
