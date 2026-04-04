<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;

/**
 * @internal
 */
final class FieldDtoTest extends TestCase
{
    public function testGettersWithoutOldValue(): void
    {
        $fieldDto = new FieldDto('name', 'name_col', 'string', 'John');

        static::assertSame('name', $fieldDto->getName());
        static::assertSame('name_col', $fieldDto->getColumnName());
        static::assertSame('string', $fieldDto->getType());
        static::assertSame('John', $fieldDto->getValue());
        static::assertSame(null, $fieldDto->getOldValue());
        static::assertSame(false, $fieldDto->hasOldValue());
    }

    public function testGettersWithOldValue(): void
    {
        $fieldDto = new FieldDto('name', 'name_col', 'string', 'John', 'Jane', hasOldValue: true);

        static::assertSame('John', $fieldDto->getValue());
        static::assertSame('Jane', $fieldDto->getOldValue());
        static::assertSame(true, $fieldDto->hasOldValue());
    }

    public function testNullableValues(): void
    {
        $fieldDto = new FieldDto('deleted_at', 'deleted_at', 'datetime', null, null);

        static::assertSame(null, $fieldDto->getValue());
        static::assertSame(null, $fieldDto->getOldValue());
    }
}
