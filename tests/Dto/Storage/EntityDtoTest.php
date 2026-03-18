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
        $dto = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user', $fields);

        static::assertSame(Operation::Insert, $dto->getOperation());
        static::assertSame('App\\Entity\\User', $dto->getClass());
        static::assertSame('user', $dto->getTableName());
        static::assertSame($fields, $dto->getFields());
    }

    public function testAllOperations(): void
    {
        foreach (Operation::cases() as $operation) {
            $dto = new EntityDto($operation, 'App\\Entity\\Foo', 'foo', []);
            static::assertSame($operation, $dto->getOperation());
        }
    }
}
