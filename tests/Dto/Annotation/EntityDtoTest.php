<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto\Annotation;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;

/**
 * @internal
 */
final class EntityDtoTest extends TestCase
{
    public function testGetClassReturnsString(): void
    {
        $dto = new EntityDto('App\\Entity\\User', ['password']);

        $result = $dto->getClass();

        static::assertIsString($result);
        static::assertSame('App\\Entity\\User', $result);
    }

    public function testGetIgnoredFieldsReturnsArray(): void
    {
        $fields = ['password', 'salt'];
        $dto = new EntityDto('App\\Entity\\User', $fields);

        $result = $dto->getIgnoredFields();

        static::assertIsArray($result);
        static::assertSame($fields, $result);
    }

    public function testGetIgnoredFieldsEmptyArray(): void
    {
        $dto = new EntityDto('App\\Entity\\User', []);

        static::assertSame([], $dto->getIgnoredFields());
    }
}
