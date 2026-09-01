<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;
use PrecisionSoft\Doctrine\Audit\Storage\FileAuditReader;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use PrecisionSoft\Doctrine\Audit\Test\Utility\AuditIntegrationEnvironment;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\RelatedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\SkipIntegrationException;

/**
 * The two storages may differ in shape - a column per field against a JSONL line with old/new pairs - but never in content.
 *
 * @internal
 */
#[Group('integration')]
final class StorageParityFunctionalTest extends TestCase
{
    private ?AuditIntegrationEnvironment $environment = null;
    private ?string $auditFile = null;

    protected function tearDown(): void
    {
        $this->environment?->close();
        $this->environment = null;

        if (null !== $this->auditFile && true === \file_exists($this->auditFile)) {
            \unlink($this->auditFile);
        }

        $this->auditFile = null;

        parent::tearDown();
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDoctrineAndFileStorageDescribeTheSameOperations(string $environmentVariable): void
    {
        try {
            $sourceConnection = IntegrationDatabase::createConnection(
                $environmentVariable,
                IntegrationDatabase::SOURCE_SCHEMA,
            );
            $auditConnection = IntegrationDatabase::createConnection(
                $environmentVariable,
                IntegrationDatabase::AUDIT_SCHEMA,
            );
        } catch (SkipIntegrationException $skipIntegrationException) {
            static::markTestSkipped($skipIntegrationException->getMessage());
        }

        $this->auditFile = \sys_get_temp_dir() . '/audit_parity_' . \uniqid() . '.log';

        $environment = $this->environment = new AuditIntegrationEnvironment(
            $sourceConnection,
            $auditConnection,
            extraStorages: [new FileStorage($this->auditFile, null)],
        );

        $related = (new RelatedSubject())->setLabel('parity');
        $environment->sourceEntityManager->persist($related);
        $environment->sourceEntityManager->flush();

        $subject = (new AuditedSubject())->setName('parity')->setSecret('s')->setModified('m');
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $subject->getRelatedSubjects()->add($related);
        $environment->sourceEntityManager->flush();

        $subject->setName('parity-updated');
        $environment->sourceEntityManager->flush();

        $subjectId = $subject->getId();

        $environment->sourceEntityManager->remove($subject);
        $environment->sourceEntityManager->flush();

        $doctrineRows = $environment->readAuditRows('audited_subject');
        $fileLines = $this->readJsonLines();

        static::assertCount(4, $doctrineRows);
        static::assertCount(4, $fileLines);

        static::assertSame(
            ['insert', 'update', 'update', 'delete'],
            \array_column($doctrineRows, 'audit_operation'),
        );
        static::assertSame(
            ['insert', 'update', 'update', 'delete'],
            \array_map(static fn(array $line) => $line['entities'][0]['operation'], $fileLines),
        );

        static::assertSame('parity', $doctrineRows[0]['name']);
        static::assertSame('parity', $fileLines[0]['entities'][0]['columns']['name']);

        $doctrineCollectionChanges = \json_decode(
            $environment->readTransactions()[1]['collection_changes'],
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        static::assertEquals($doctrineCollectionChanges, $fileLines[1]['collections']);

        static::assertSame('parity-updated', $doctrineRows[2]['name']);
        static::assertSame(
            ['old' => 'parity', 'new' => 'parity-updated'],
            $fileLines[2]['entities'][0]['columns']['name'],
        );

        foreach ($fileLines as $fileLine) {
            static::assertArrayNotHasKey('secret', $fileLine['entities'][0]['columns']);
            static::assertArrayNotHasKey('modified', $fileLine['entities'][0]['columns']);
        }

        foreach ($fileLines as $fileLine) {
            static::assertSame('integration', $fileLine['username']);
        }

        /* the reader has to match the shape the storage really writes, not the one the unit fixtures assume */
        $reader = new FileAuditReader((string)$this->auditFile);

        static::assertCount(
            1,
            $reader->read(new AuditQuery(
                entityClass: RelatedSubject::class,
                identity: ['id' => $related->getId()],
            ))->getTransactions(),
            'the added target of the collection is reachable through its own class and id',
        );
        static::assertCount(
            4,
            $reader->read(new AuditQuery(entityClass: AuditedSubject::class))->getTransactions(),
        );
        static::assertCount(
            1,
            $reader->read(new AuditQuery(
                entityClass: AuditedSubject::class,
                identity: ['id' => $subjectId],
                operation: Operation::Delete,
            ))->getTransactions(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function readJsonLines(): array
    {
        static::assertNotNull($this->auditFile);

        $contents = \file_get_contents($this->auditFile);
        static::assertNotFalse($contents);

        $lines = \array_filter(\explode(\PHP_EOL, \trim($contents)));

        return \array_map(
            static fn(string $line) => \json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            \array_values($lines),
        );
    }
}
