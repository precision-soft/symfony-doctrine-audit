<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Attribute;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;

/**
 * @internal
 */
final class AuditableTest extends TestCase
{
    public function testDefaultEnabled(): void
    {
        $auditable = new Auditable();

        static::assertSame(true, $auditable->enabled);
    }

    public function testExplicitEnabled(): void
    {
        $auditable = new Auditable(true);

        static::assertSame(true, $auditable->enabled);
    }

    public function testDisabled(): void
    {
        $auditable = new Auditable(false);

        static::assertSame(false, $auditable->enabled);
    }
}
