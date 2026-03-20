<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Auditor;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration;

/**
 * @internal
 */
final class ConfigurationTest extends TestCase
{
    public function testGetIgnoredFieldsReturnsArray(): void
    {
        $fields = ['password', 'salt'];
        $configuration = new Configuration($fields);

        $result = $configuration->getIgnoredFields();

        static::assertIsArray($result);
        static::assertSame($fields, $result);
    }

    public function testGetIgnoredFieldsEmptyArray(): void
    {
        $configuration = new Configuration([]);

        static::assertSame([], $configuration->getIgnoredFields());
    }
}
