<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use PrecisionSoft\Doctrine\Audit\Test\Utility\AuditIntegrationEnvironment;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\RecordingLogger;
use PrecisionSoft\Doctrine\Audit\Test\Utility\SkipIntegrationException;

/** @internal */
#[Group('integration')]
final class AuditFailureFunctionalTest extends TestCase
{
    private ?AuditIntegrationEnvironment $environment = null;
    private ?string $auditFile = null;

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAnUnwritableAuditSinkStillLeavesTheEntityCommitted(string $environmentVariable): void
    {
        $logger = new RecordingLogger();
        $environment = $this->createEnvironment($environmentVariable, $logger);

        $environment->auditEntityManager->getConnection()->executeStatement('DROP TABLE `audited_subject`');

        $subject = (new AuditedSubject())->setName('committed')->setSecret('s')->setModified('m');
        $environment->sourceEntityManager->persist($subject);

        try {
            $environment->sourceEntityManager->flush();
            static::fail('the audit failure must surface to the caller');
        } catch (Exception $exception) {
            static::assertStringContainsStringIgnoringCase('audited_subject', $exception->getMessage());
        }

        $count = $environment->sourceEntityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `audited_subject` WHERE `name` = ?',
            ['committed'],
        );
        static::assertSame(1, (int)$count);

        $criticals = $logger->getRecords('critical');
        static::assertCount(1, $criticals);
        static::assertStringContainsString('audit_dead_letter', $criticals[0]['message']);
        static::assertArrayHasKey('storage_dto', $criticals[0]['context']);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testASurvivingSinkMeansNoDeadLetter(string $environmentVariable): void
    {
        $logger = new RecordingLogger();

        $this->auditFile = \sys_get_temp_dir() . '/audit_integration_' . \uniqid() . '.log';

        $environment = $this->createEnvironment(
            $environmentVariable,
            $logger,
            [new FileStorage($this->auditFile, $logger)],
        );

        $environment->auditEntityManager->getConnection()->executeStatement('DROP TABLE `audited_subject`');

        $subject = (new AuditedSubject())->setName('survivor')->setSecret('s')->setModified('m');
        $environment->sourceEntityManager->persist($subject);

        try {
            $environment->sourceEntityManager->flush();
            static::fail('the failing sink must still surface to the caller');
        } catch (Exception) {
        }

        static::assertSame([], $logger->getRecords('critical'), 'a surviving sink is not a dead letter');

        $errors = $logger->getRecords('error');
        static::assertNotSame([], $errors);

        $contents = \file_get_contents($this->auditFile);
        static::assertNotFalse($contents);
        static::assertStringContainsString('survivor', $contents);
    }

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

    /** @param StorageInterface[] $extraStorages */
    private function createEnvironment(
        string $environmentVariable,
        RecordingLogger $logger,
        array $extraStorages = [],
    ): AuditIntegrationEnvironment {
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

        return $this->environment = new AuditIntegrationEnvironment(
            $sourceConnection,
            $auditConnection,
            extraStorages: $extraStorages,
            logger: $logger,
        );
    }
}
