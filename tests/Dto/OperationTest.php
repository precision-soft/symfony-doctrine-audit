<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

/**
 * @internal
 */
final class OperationTest extends TestCase
{
    public function testValues(): void
    {
        static::assertSame(['delete', 'insert', 'update'], Operation::values());
    }

    public function testBackedValues(): void
    {
        static::assertSame('delete', Operation::Delete->value);
        static::assertSame('insert', Operation::Insert->value);
        static::assertSame('update', Operation::Update->value);
    }

    public function testFromString(): void
    {
        static::assertSame(Operation::Delete, Operation::from('delete'));
        static::assertSame(Operation::Insert, Operation::from('insert'));
        static::assertSame(Operation::Update, Operation::from('update'));
    }
}
