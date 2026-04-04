<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto\Auditor;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

/**
 * @internal
 */
final class EntityDtoTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $entityDto = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user');

        static::assertSame(Operation::Insert, $entityDto->getOperation());
        static::assertSame('App\\Entity\\User', $entityDto->getClass());
        static::assertSame('user', $entityDto->getTableName());
        static::assertSame([], $entityDto->getFields());
    }

    public function testAddField(): void
    {
        $entityDto = new EntityDto(Operation::Update, 'App\\Entity\\User', 'user');
        $fieldDto = new FieldDto('name', 'name', 'string', 'John');

        $result = $entityDto->addField($fieldDto);

        static::assertSame($entityDto, $result);
        static::assertCount(1, $entityDto->getFields());
        static::assertSame($fieldDto, $entityDto->getFields()[0]);
    }

    public function testAddMultipleFields(): void
    {
        $entityDto = new EntityDto(Operation::Delete, 'App\\Entity\\User', 'user');
        $idField = new FieldDto('id', 'id', 'integer', 1);
        $nameField = new FieldDto('name', 'name', 'string', 'John');

        $entityDto->addField($idField);
        $entityDto->addField($nameField);

        static::assertCount(2, $entityDto->getFields());
        static::assertSame($idField, $entityDto->getFields()[0]);
        static::assertSame($nameField, $entityDto->getFields()[1]);
    }

    public function testAllOperations(): void
    {
        foreach (Operation::cases() as $operation) {
            $entityDto = new EntityDto($operation, 'App\\Entity\\Foo', 'foo');
            static::assertSame($operation, $entityDto->getOperation());
        }
    }
}
