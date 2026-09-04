<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Test\Utility;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * `DATABASE_URL_*` is exported whether or not the `db` profile runs, so the connection is attempted and the skip
 * comes from the exception. The catalogue and its trail are two databases on the same server, which is the bundle's
 * rule, so both are created here from the one url the container hands over.
 *
 * @internal
 */
final class CatalogueDatabase
{
    public const CATALOGUE_SCHEMA = 'example_catalogue';
    public const TRAIL_SCHEMA = 'example_catalogue_trail';

    /** @return iterable<string, array{string}> */
    public static function dataProviderEngine(): iterable
    {
        yield 'mysql' => ['DATABASE_URL_MYSQL'];
        yield 'mariadb' => ['DATABASE_URL_MARIADB'];
    }

    /** @throws SkipException when the engine is not there */
    public static function getDatabaseUrl(string $environmentVariable, string $schema): string
    {
        $databaseUrl = \getenv($environmentVariable);

        if (false === \is_string($databaseUrl) || '' === $databaseUrl) {
            throw new SkipException(\sprintf('`%s` is not set - this suite expects the dev container', $environmentVariable));
        }

        static::createSchema($databaseUrl, $environmentVariable, $schema);

        /* only the database name changes; the `serverVersion` the container sets has to survive, or dbal guesses the platform */
        [$base, $query] = \array_pad(\explode('?', $databaseUrl, 2), 2, null);
        $path = \substr((string)$base, 0, (int)\strrpos((string)$base, '/') + 1);

        return $path . $schema . (null === $query ? '' : '?' . $query);
    }

    /** @throws SkipException when the engine is not there */
    public static function createSchema(string $databaseUrl, string $environmentVariable, string $schema): void
    {
        $parameters = static::parse($databaseUrl);
        unset($parameters['dbname']);

        $connection = DriverManager::getConnection($parameters);

        try {
            $connection->executeStatement(\sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $schema));
        } catch (DbalException $dbalException) {
            throw new SkipException(\sprintf(
                'cannot reach the database behind `%s` (%s) - start it with `./dc --profile db up -d`',
                $environmentVariable,
                $dbalException->getMessage(),
            ));
        } finally {
            $connection->close();
        }
    }

    /** @return array<string, mixed> */
    private static function parse(string $databaseUrl): array
    {
        return (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($databaseUrl);
    }
}
