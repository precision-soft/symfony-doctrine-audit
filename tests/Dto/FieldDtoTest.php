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
        $field = new FieldDto('name', 'name_col', 'string', 'John');

        static::assertSame('name', $field->getName());
        static::assertSame('name_col', $field->getColumnName());
        static::assertSame('string', $field->getType());
        static::assertSame('John', $field->getValue());
        static::assertNull($field->getOldValue());
    }

    public function testGettersWithOldValue(): void
    {
        $field = new FieldDto('name', 'name_col', 'string', 'John', 'Jane');

        static::assertSame('John', $field->getValue());
        static::assertSame('Jane', $field->getOldValue());
    }

    public function testNullableValues(): void
    {
        $field = new FieldDto('deleted_at', 'deleted_at', 'datetime', null, null);

        static::assertNull($field->getValue());
        static::assertNull($field->getOldValue());
    }
}
