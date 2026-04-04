<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto\Storage;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto;

/**
 * @internal
 */
final class EntityDtoTest extends TestCase
{
    public function testGetters(): void
    {
        $fields = [new FieldDto('id', 'id', 'integer', 1)];
        $entityDto = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', $fields);

        static::assertSame(Operation::Insert, $entityDto->getOperation());
        static::assertSame('App\\Entity\\User', $entityDto->getClass());
        static::assertSame('user', $entityDto->getTableName());
        static::assertSame($fields, $entityDto->getFields());
    }

    public function testAllOperations(): void
    {
        foreach (Operation::cases() as $operation) {
            $entityDto = new EntityDto($operation, 'App\\Entity\\Foo', 'foo', []);
            static::assertSame($operation, $entityDto->getOperation());
        }
    }
}
