<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test;

use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\PrecisionSoftDoctrineAuditBundle;
use PrecisionSoft\Doctrine\Audit\Type\AuditOperationType;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @internal
 */
final class PrecisionSoftDoctrineAuditBundleTest extends TestCase
{
    public function testExtendsBundle(): void
    {
        $precisionSoftDoctrineAuditBundle = new PrecisionSoftDoctrineAuditBundle();

        static::assertInstanceOf(Bundle::class, $precisionSoftDoctrineAuditBundle);
    }

    public function testBootRegistersAuditOperationType(): void
    {
        /** @info remove type if previously registered by another test */
        $typeName = AuditOperationType::getDefaultName();
        if (true === Type::hasType($typeName)) {
            Type::overrideType($typeName, AuditOperationType::class);
        }

        $precisionSoftDoctrineAuditBundle = new PrecisionSoftDoctrineAuditBundle();
        $precisionSoftDoctrineAuditBundle->boot();

        static::assertSame(true, Type::hasType($typeName));
        static::assertInstanceOf(AuditOperationType::class, Type::getType($typeName));
    }

    public function testBootDoesNotReRegisterExistingType(): void
    {
        $typeName = AuditOperationType::getDefaultName();

        /** @info ensure type is registered first */
        if (false === Type::hasType($typeName)) {
            Type::addType($typeName, AuditOperationType::class);
        }

        $precisionSoftDoctrineAuditBundle = new PrecisionSoftDoctrineAuditBundle();

        /** @info should not throw even though type already exists */
        $precisionSoftDoctrineAuditBundle->boot();

        static::assertSame(true, Type::hasType($typeName));
    }
}
