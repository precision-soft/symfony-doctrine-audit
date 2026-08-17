<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfiguration;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as StorageConfiguration;
use PrecisionSoft\Doctrine\Audit\Test\Utility\AuditSchemaBuilder;
use PrecisionSoft\Doctrine\Audit\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\SkipIntegrationException;

/** @internal */
#[Group('integration')]
final class SchemaFunctionalTest extends TestCase
{
    private ?Connection $sourceConnection = null;
    private ?Connection $auditConnection = null;

    protected function tearDown(): void
    {
        foreach ([$this->auditConnection, $this->sourceConnection] as $connection) {
            if (null !== $connection) {
                IntegrationDatabase::dropAllTables($connection);
                $connection->close();
            }
        }

        $this->auditConnection = null;
        $this->sourceConnection = null;

        parent::tearDown();
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAuditSchemaIsCreatedOnlyForAuditedEntities(string $environmentVariable): void
    {
        [, $auditEntityManager, $auditSchemaBuilder] = $this->createEnvironment($environmentVariable);

        $auditSchemaBuilder->create();

        $tableNames = IntegrationDatabase::listTables($auditEntityManager->getConnection());

        static::assertContains('audit_transaction', $tableNames);
        static::assertContains('audited_subject', $tableNames);
        static::assertNotContains(
            'unaudited_subject',
            $tableNames,
            'an entity without #[Auditable] must not receive an audit table',
        );
        static::assertNotContains(
            'related_subject',
            $tableNames,
            'the target of an audited association is not itself audited',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAuditTableShapeMatchesTheAuditedEntity(string $environmentVariable): void
    {
        [, $auditEntityManager, $auditSchemaBuilder] = $this->createEnvironment($environmentVariable);

        $auditSchemaBuilder->create();

        $auditTable = $auditEntityManager->getConnection()->createSchemaManager()->introspectTable('audited_subject');

        $columnNames = \array_map(
            static fn($column) => $column->getName(),
            $auditTable->getColumns(),
        );

        static::assertContains('id', $columnNames);
        static::assertContains('name', $columnNames);
        static::assertContains('related_subject_id', $columnNames);
        static::assertContains('audit_transaction_id', $columnNames);
        static::assertContains('audit_operation', $columnNames);

        static::assertNotContains('secret', $columnNames);
        static::assertNotContains('modified', $columnNames);

        $primaryKey = $auditTable->getPrimaryKey();
        static::assertNotNull($primaryKey);
        static::assertSame(['id', 'audit_transaction_id'], $primaryKey->getColumns());

        /* the audited copy must not autoincrement: every version of the row is kept */
        static::assertFalse($auditTable->getColumn('id')->getAutoincrement());
        static::assertFalse($auditTable->getColumn('name')->getNotnull());
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testEveryAuditTableReferencesTheTransactionTable(string $environmentVariable): void
    {
        [, $auditEntityManager, $auditSchemaBuilder] = $this->createEnvironment($environmentVariable);

        $auditSchemaBuilder->create();

        $auditTable = $auditEntityManager->getConnection()->createSchemaManager()->introspectTable('audited_subject');

        $foreignKeys = $auditTable->getForeignKeys();
        static::assertCount(1, $foreignKeys);

        $foreignKey = \array_values($foreignKeys)[0];
        static::assertSame('audit_transaction', $foreignKey->getForeignTableName());
        static::assertSame(['audit_transaction_id'], $foreignKey->getLocalColumns());

        /* asked of the server and not of getOption('onDelete'), which introspects as null because RESTRICT is the engine default and is stored as no explicit rule */
        $deleteRule = $auditEntityManager->getConnection()->fetchOne(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['audited_subject', $foreignKey->getName()],
        );

        static::assertSame('RESTRICT', $deleteRule);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testIgnoreOnAPrivateMappedSuperclassPropertyIsHonoured(string $environmentVariable): void
    {
        [, $auditEntityManager, $auditSchemaBuilder] = $this->createEnvironment($environmentVariable);

        $auditSchemaBuilder->create();

        $auditTable = $auditEntityManager->getConnection()->createSchemaManager()->introspectTable('inheriting_subject');

        $columnNames = \array_map(
            static fn($column) => $column->getName(),
            $auditTable->getColumns(),
        );

        static::assertContains('email', $columnNames, 'a plain inherited field must still be audited');
        static::assertNotContains(
            'password',
            $columnNames,
            '#[Ignore] on a private property of a mapped superclass must keep the field out of the audit table',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testCreatingTheSchemaTwiceLeavesNothingToUpdate(string $environmentVariable): void
    {
        [, , $auditSchemaBuilder] = $this->createEnvironment($environmentVariable);

        $auditSchemaBuilder->create();

        $updateSql = $auditSchemaBuilder->getUpdateSql();

        static::assertSame(
            [],
            $updateSql,
            \sprintf('schema:update is not idempotent, it still wants to run: %s', \implode('; ', $updateSql)),
        );
    }

    /** @return array{EntityManagerInterface, EntityManagerInterface, AuditSchemaBuilder} */
    private function createEnvironment(string $environmentVariable): array
    {
        try {
            $this->sourceConnection = IntegrationDatabase::createConnection(
                $environmentVariable,
                IntegrationDatabase::SOURCE_SCHEMA,
            );
            $this->auditConnection = IntegrationDatabase::createConnection(
                $environmentVariable,
                IntegrationDatabase::AUDIT_SCHEMA,
            );
        } catch (SkipIntegrationException $skipIntegrationException) {
            static::markTestSkipped($skipIntegrationException->getMessage());
        }

        IntegrationDatabase::registerAuditOperationType();
        IntegrationDatabase::dropAllTables($this->auditConnection);

        $sourceEntityManager = IntegrationDatabase::createEntityManager($this->sourceConnection);
        $auditEntityManager = IntegrationDatabase::createEntityManager($this->auditConnection);

        $auditSchemaBuilder = new AuditSchemaBuilder(
            $sourceEntityManager,
            $auditEntityManager,
            new AnnotationReadService(),
            new AuditorConfiguration(['modified']),
            new StorageConfiguration([]),
        );

        return [$sourceEntityManager, $auditEntityManager, $auditSchemaBuilder];
    }
}
