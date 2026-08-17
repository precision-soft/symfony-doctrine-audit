<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfiguration;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as StorageConfiguration;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Storage;
use Psr\Log\LoggerInterface;

/**
 * Wired the way the DI extension wires it: the auditor listens on the SOURCE entity manager, the doctrine storage writes through the AUDIT one, and the two must be separate schemas.
 *
 * @internal
 */
final class AuditIntegrationEnvironment
{
    public readonly EntityManagerInterface $sourceEntityManager;
    public readonly EntityManagerInterface $auditEntityManager;
    public readonly Auditor $auditor;
    public readonly StorageConfiguration $storageConfiguration;

    /**
     * @param string[] $ignoredFields the auditor's global ignored_fields
     * @param StorageInterface[] $extraStorages appended after the doctrine storage, in call order
     * @param array<string, mixed> $extras the transaction provider's extras payload
     */
    public function __construct(
        private readonly Connection $sourceConnection,
        private readonly Connection $auditConnection,
        array $ignoredFields = ['modified'],
        array $extraStorages = [],
        ?LoggerInterface $logger = null,
        string $username = 'integration',
        array $extras = [],
    ) {
        IntegrationDatabase::registerAuditOperationType();

        $this->sourceEntityManager = IntegrationDatabase::createEntityManager($sourceConnection);
        $this->auditEntityManager = IntegrationDatabase::createEntityManager($auditConnection);
        $this->storageConfiguration = new StorageConfiguration([]);

        $annotationReadService = new AnnotationReadService();
        $auditorConfiguration = new AuditorConfiguration($ignoredFields);

        IntegrationDatabase::dropAllTables($auditConnection);
        IntegrationDatabase::dropAllTables($sourceConnection);

        $this->createSourceSchema();

        (new AuditSchemaBuilder(
            $this->sourceEntityManager,
            $this->auditEntityManager,
            $annotationReadService,
            $auditorConfiguration,
            $this->storageConfiguration,
        ))->create();

        $storages = \array_merge(
            [new Storage($this->auditEntityManager, $this->storageConfiguration, $logger)],
            $extraStorages,
        );

        $this->auditor = new Auditor(
            $auditorConfiguration,
            $this->sourceEntityManager,
            $storages,
            new FixedTransactionProvider($username, $extras),
            $logger,
            $annotationReadService,
        );

        $this->sourceEntityManager->getEventManager()->addEventListener(
            [Events::onFlush, Events::postFlush],
            $this->auditor,
        );
    }

    /** @return array<int, array<string, mixed>> every audit row of a table, oldest transaction first */
    public function readAuditRows(string $tableName): array
    {
        return $this->auditConnection->fetchAllAssociative(
            \sprintf('SELECT * FROM `%s` ORDER BY `audit_transaction_id` ASC', $tableName),
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function readTransactions(): array
    {
        return $this->auditConnection->fetchAllAssociative(
            \sprintf('SELECT * FROM `%s` ORDER BY `id` ASC', $this->storageConfiguration->getTransactionTableName()),
        );
    }

    public function close(): void
    {
        IntegrationDatabase::dropAllTables($this->auditConnection);
        IntegrationDatabase::dropAllTables($this->sourceConnection);

        $this->auditConnection->close();
        $this->sourceConnection->close();
    }

    private function createSourceSchema(): void
    {
        $metadatas = $this->sourceEntityManager->getMetadataFactory()->getAllMetadata();

        (new SchemaTool($this->sourceEntityManager))->createSchema($metadatas);
    }
}
