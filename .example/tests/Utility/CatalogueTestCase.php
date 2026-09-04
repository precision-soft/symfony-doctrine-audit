<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Test\Utility;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\CreateCommand;
use PrecisionSoft\Doctrine\Audit\Example\CatalogueKernel;
use PrecisionSoft\Doctrine\Audit\Example\Service\Catalogue;
use PrecisionSoft\Doctrine\Audit\Example\Service\CatalogueTransactionProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots the catalogue the way an application does - through the kernel, so the bundle's own extension wires the
 * auditor, the two storages and the commands - and creates both schemas: the catalogue with the ORM's schema tool,
 * the trail with the bundle's own command.
 *
 * @internal
 */
abstract class CatalogueTestCase extends TestCase
{
    protected ?CatalogueKernel $kernel = null;
    protected ?string $auditFile = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        foreach ([$this->auditFile, $this->auditFile . '.purge'] as $file) {
            if (null !== $this->auditFile && null !== $file && true === \file_exists($file)) {
                \unlink($file);
            }
        }

        $this->auditFile = null;

        parent::tearDown();
    }

    protected function boot(string $environmentVariable): CatalogueKernel
    {
        try {
            $catalogueUrl = CatalogueDatabase::getDatabaseUrl($environmentVariable, CatalogueDatabase::CATALOGUE_SCHEMA);
            $trailUrl = CatalogueDatabase::getDatabaseUrl($environmentVariable, CatalogueDatabase::TRAIL_SCHEMA);
        } catch (SkipException $skipException) {
            static::markTestSkipped($skipException->getMessage());
        }

        $this->auditFile = \sys_get_temp_dir() . '/catalogue_trail_' . \uniqid() . '.log';

        /* the container is compiled per environment, so a second engine in the same process gets its own cache directory */
        $environment = \strtolower($environmentVariable);

        (new Filesystem())->remove(\dirname(__DIR__, 2) . '/var/cache/' . $environment);

        $kernel = new CatalogueKernel($environment, $catalogueUrl, $trailUrl, $this->auditFile);
        $kernel->boot();

        $this->kernel = $kernel;

        $this->createCatalogueSchema();
        $this->createTrailSchema();

        return $kernel;
    }

    protected function getCatalogue(): Catalogue
    {
        $catalogue = $this->getContainerService(Catalogue::class);

        static::assertInstanceOf(Catalogue::class, $catalogue);

        return $catalogue;
    }

    protected function getTransactionProvider(): CatalogueTransactionProvider
    {
        $transactionProvider = $this->getContainerService(CatalogueTransactionProvider::class);

        static::assertInstanceOf(CatalogueTransactionProvider::class, $transactionProvider);

        return $transactionProvider;
    }

    protected function getCatalogueEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->getContainerService('doctrine.orm.catalogue_entity_manager');

        static::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    protected function getTrailConnection(): Connection
    {
        $connection = $this->getContainerService('doctrine.dbal.trail_connection');

        static::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    protected function getContainerService(string $serviceId): ?object
    {
        static::assertNotNull($this->kernel);

        return $this->kernel->getContainer()->get($serviceId);
    }

    /** @return array<int, array<string, mixed>> the trail's transactions, oldest first */
    protected function readTransactions(): array
    {
        return $this->getTrailConnection()->fetchAllAssociative(
            'SELECT * FROM `audit_transaction` ORDER BY `id` ASC',
        );
    }

    /** @return array<int, array<string, mixed>> every audit row of one table, oldest transaction first */
    protected function readAuditRows(string $tableName): array
    {
        return $this->getTrailConnection()->fetchAllAssociative(
            \sprintf('SELECT * FROM `%s` ORDER BY `audit_transaction_id` ASC', $tableName),
        );
    }

    /** @return array<int, array<string, mixed>> the jsonl storage's lines, oldest first */
    protected function readJsonLines(): array
    {
        static::assertNotNull($this->auditFile);

        if (false === \is_file($this->auditFile)) {
            return [];
        }

        $contents = \file_get_contents($this->auditFile);

        static::assertNotFalse($contents);

        return \array_map(
            static fn(string $line) => \json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            \array_values(\array_filter(\explode(\PHP_EOL, \trim($contents)))),
        );
    }

    /** @return string[] the tables of the trail database */
    protected function listTrailTables(): array
    {
        $tableNames = $this->getTrailConnection()->createSchemaManager()->listTableNames();

        \sort($tableNames);

        return $tableNames;
    }

    private function createCatalogueSchema(): void
    {
        $entityManager = $this->getCatalogueEntityManager();
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $this->dropEverything($entityManager->getConnection());
        $schemaTool->createSchema($metadata);
    }

    /** The bundle's own command builds the trail, which is what an application runs once per deployment. */
    private function createTrailSchema(): void
    {
        $this->dropEverything($this->getTrailConnection());

        $command = $this->getContainerService(CatalogueKernel::SCHEMA_CREATE_COMMAND);

        static::assertInstanceOf(CreateCommand::class, $command);

        /* without `--force` the command only prints the sql, which is what makes it safe to run against production */
        static::assertSame(0, (new CommandTester($command))->execute(['--force' => true]));
    }

    private function dropEverything(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($schemaManager->listTableNames() as $tableName) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $tableName));
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
