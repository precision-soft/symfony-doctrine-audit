<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Exception\FixtureException;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Type\SubjectReferenceType;
use PrecisionSoft\Doctrine\Audit\Type\AuditOperationType;

/**
 * Every helper takes the schema name explicitly because audit tables carry the same name as the entity tables they mirror, so the source and the audit schema can never be the same database.
 *
 * @internal
 */
final class IntegrationDatabase
{
    public const SOURCE_SCHEMA = 'audit_source';
    public const AUDIT_SCHEMA = 'audit_target';

    /** @return iterable<string, array{string}> */
    public static function dataProviderEngine(): iterable
    {
        yield 'mysql' => ['DATABASE_URL_MYSQL'];
        yield 'mariadb' => ['DATABASE_URL_MARIADB'];
    }

    /**
     * The scheme map is required: DBAL's driver names are `pdo_mysql`-style, so a bare `mysql://` DSN resolves to no driver. Parsing stays outside the try on purpose, so a malformed DSN fails loudly and only an unreachable server becomes a skip.
     *
     * @throws SkipIntegrationException when the server is unreachable, so the caller can skip rather than fail
     */
    public static function createConnection(string $environmentVariable, ?string $schema = null): Connection
    {
        $databaseUrl = \getenv($environmentVariable);

        if (false === $databaseUrl || '' === $databaseUrl) {
            throw new SkipIntegrationException(\sprintf(
                '`%s` is not set - this suite expects the dev container from `.dev/docker/`',
                $environmentVariable,
            ));
        }

        $parameters = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($databaseUrl);

        if (null !== $schema) {
            $parameters['dbname'] = $schema;
        }

        /* the schema must exist before a connection can select it, hence the bootstrap connection without one */
        if (null !== $schema) {
            $parametersWithoutSchema = $parameters;
            unset($parametersWithoutSchema['dbname']);

            $bootstrapConnection = DriverManager::getConnection($parametersWithoutSchema);

            try {
                $bootstrapConnection->executeStatement(\sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $schema));
            } catch (DbalException $dbalException) {
                throw new SkipIntegrationException(\sprintf(
                    'cannot reach the database behind `%s` (%s) - start it with `./dc --profile db up -d`',
                    $environmentVariable,
                    $dbalException->getMessage(),
                ));
            } finally {
                $bootstrapConnection->close();
            }
        }

        $connection = DriverManager::getConnection($parameters);

        try {
            $connection->executeQuery('SELECT 1');
        } catch (DbalException $dbalException) {
            throw new SkipIntegrationException(\sprintf(
                'cannot reach the database behind `%s` (%s) - start it with `./dc --profile db up -d`',
                $environmentVariable,
                $dbalException->getMessage(),
            ));
        }

        return $connection;
    }

    /** Not `ORMSetup::createAttributeMetadataConfiguration()`, which installs a PSR-6 cache and so hard-requires `symfony/cache`; building `Configuration` directly also leaves the caches unset, so a mapping change cannot be masked. */
    public static function createEntityManager(Connection $connection): EntityManagerInterface
    {
        /* the fixture entity set maps custom types, so every entity manager built for the tests needs them - a schema build that skips this dies on an unknown column type */
        static::registerSubjectReferenceType();

        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity']));
        $configuration->setProxyDir(\sys_get_temp_dir() . '/precision-soft-doctrine-audit-proxies');
        $configuration->setProxyNamespace('PrecisionSoftDoctrineAuditTestProxies');
        $configuration->setAutoGenerateProxyClasses(true);

        return new EntityManager($connection, $configuration);
    }

    /** Doctrine's type registry is global, so the `hasType` guard is required: a second `addType()` throws. */
    public static function registerSubjectReferenceType(): void
    {
        if (false === Type::hasType(SubjectReferenceType::NAME)) {
            Type::addType(SubjectReferenceType::NAME, SubjectReferenceType::class);
        }
    }

    /** Doctrine's type registry is global, so the `hasType` guard is required: a second `addType()` throws. */
    public static function registerAuditOperationType(): void
    {
        $typeName = AuditOperationType::getDefaultName();

        if (false === Type::hasType($typeName)) {
            Type::addType($typeName, AuditOperationType::class);
        }

        if (false === (Type::getType($typeName) instanceof AuditOperationType)) {
            throw new FixtureException(\sprintf('`%s` is not the audit operation type', $typeName));
        }
    }

    /** @return string[] the tables present in the connection's schema */
    public static function listTables(Connection $connection): array
    {
        $tableNames = $connection->createSchemaManager()->listTableNames();

        \sort($tableNames);

        return $tableNames;
    }

    public static function dropAllTables(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($schemaManager->listTableNames() as $tableName) {
                $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $tableName));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
