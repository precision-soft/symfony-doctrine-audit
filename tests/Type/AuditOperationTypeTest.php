<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Type;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Type\AuditOperationType;
use PrecisionSoft\Doctrine\Type\Contract\AbstractEnumType;

/**
 * @internal
 */
final class AuditOperationTypeTest extends TestCase
{
    public function testGetValuesReturnsOperationValues(): void
    {
        $auditOperationType = new AuditOperationType();

        $values = $auditOperationType->getValues();

        static::assertSame(Operation::values(), $values);
        static::assertSame(['delete', 'insert', 'update'], $values);
    }

    public function testGetValuesContainsAllCases(): void
    {
        $auditOperationType = new AuditOperationType();

        $values = $auditOperationType->getValues();

        foreach (Operation::cases() as $case) {
            static::assertContains($case->value, $values);
        }
    }

    public function testInstanceOfAbstractEnumType(): void
    {
        $auditOperationType = new AuditOperationType();

        static::assertInstanceOf(AbstractEnumType::class, $auditOperationType);
    }
}
