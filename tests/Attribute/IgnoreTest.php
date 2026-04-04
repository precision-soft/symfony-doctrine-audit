<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Attribute;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Attribute\Ignore;

/**
 * @internal
 */
final class IgnoreTest extends TestCase
{
    public function testDefaultEnabled(): void
    {
        $ignore = new Ignore();

        static::assertSame(true, $ignore->enabled);
    }

    public function testExplicitEnabled(): void
    {
        $ignore = new Ignore(true);

        static::assertSame(true, $ignore->enabled);
    }

    public function testDisabled(): void
    {
        $ignore = new Ignore(false);

        static::assertSame(false, $ignore->enabled);
    }
}
