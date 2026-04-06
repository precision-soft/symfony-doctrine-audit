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

        $ignoredFields = $configuration->getIgnoredFields();

        static::assertIsArray($ignoredFields);
        static::assertSame($fields, $ignoredFields);
    }

    public function testGetIgnoredFieldsEmptyArray(): void
    {
        $configuration = new Configuration([]);

        static::assertSame([], $configuration->getIgnoredFields());
    }

    public function testGetIgnoredFieldsSingleField(): void
    {
        $configuration = new Configuration(['secret']);

        static::assertCount(1, $configuration->getIgnoredFields());
        static::assertSame('secret', $configuration->getIgnoredFields()[0]);
    }

    public function testGetIgnoredFieldsPreservesOrder(): void
    {
        $fields = ['z_field', 'a_field', 'm_field'];
        $configuration = new Configuration($fields);

        static::assertSame($fields, $configuration->getIgnoredFields());
    }

    public function testGetIgnoredFieldsReturnsSameInstance(): void
    {
        $fields = ['password'];
        $configuration = new Configuration($fields);

        $firstIgnoredFields = $configuration->getIgnoredFields();
        $secondIgnoredFields = $configuration->getIgnoredFields();

        static::assertSame($firstIgnoredFields, $secondIgnoredFields);
    }
}
