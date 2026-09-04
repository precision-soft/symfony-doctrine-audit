<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Functional;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Command\Audit\PurgeCommand;
use PrecisionSoft\Doctrine\Audit\Command\Audit\ReadCommand;
use PrecisionSoft\Doctrine\Audit\Storage\FileAuditReader;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use PrecisionSoft\Doctrine\Audit\Test\Utility\AuditIntegrationEnvironment;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\RelatedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\SkipIntegrationException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The two audit commands driven over a file a real flush wrote, which is the only way to prove that what the storage
 * writes is what the reader and the purge understand.
 *
 * @internal
 */
#[Group('integration')]
final class AuditCommandsFunctionalTest extends TestCase
{
    private ?AuditIntegrationEnvironment $environment = null;
    private ?string $auditFile = null;

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testReadFindsTheTransactionsOfARealFlushAndPagesThroughThem(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);
        $subjectId = $this->writeAuditTrail($environment);

        $readCommand = new CommandTester(new ReadCommand('audit:read', new FileAuditReader((string)$this->auditFile)));

        static::assertSame(0, $readCommand->execute([
            '--entity-class' => AuditedSubject::class,
            '--identity' => [\sprintf('id=%d', $subjectId)],
            '--operation' => 'update',
        ]));

        $display = $readCommand->getDisplay();

        /* linking a target writes an owner `update` of its own, so the trail carries two: the link and the rename */
        static::assertStringContainsString('2 transaction(s)', $display);
        static::assertStringContainsString('relatedSubjects +1 -0', $display);

        /* the stamp the storage writes carries its offset, so a reader in another timezone reads the same instant */
        static::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/', $display);

        $deleteCommand = new CommandTester(new ReadCommand('audit:read', new FileAuditReader((string)$this->auditFile)));

        static::assertSame(0, $deleteCommand->execute([
            '--entity-class' => AuditedSubject::class,
            '--identity' => [\sprintf('id=%d', $subjectId)],
            '--operation' => 'delete',
        ]));
        static::assertStringContainsString('1 transaction(s)', $deleteCommand->getDisplay());

        /* the cursor is the storage's own line numbering, so it only means anything against a file the storage wrote */
        $firstPage = new CommandTester(new ReadCommand('audit:read', new FileAuditReader((string)$this->auditFile)));
        static::assertSame(0, $firstPage->execute(['--limit' => '1']));

        $firstPageDisplay = $firstPage->getDisplay();
        static::assertStringContainsString('--cursor=', $firstPageDisplay);

        $matches = [];
        static::assertSame(1, \preg_match('/--cursor=(\S+)/', $firstPageDisplay, $matches));

        $cursor = $matches[1] ?? null;
        static::assertIsString($cursor);

        $secondPage = new CommandTester(new ReadCommand('audit:read', new FileAuditReader((string)$this->auditFile)));
        static::assertSame(0, $secondPage->execute(['--limit' => '1', '--cursor' => $cursor]));
        static::assertStringContainsString('1 transaction(s)', $secondPage->getDisplay());
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testPurgeWalksTheFileOneBoundedBatchPerRun(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);
        $this->writeAuditTrail($environment);

        static::assertCount(4, $this->readLines());

        $before = (new DateTimeImmutable('+1 second'))->format(DateTimeInterface::ATOM);

        $dryRun = new CommandTester(new PurgeCommand('audit:purge', new FileAuditReader((string)$this->auditFile)));
        static::assertSame(0, $dryRun->execute(['--before' => $before, '--batch-size' => '2']));
        static::assertStringContainsString('nothing was purged', $dryRun->getDisplay());
        static::assertCount(4, $this->readLines(), 'a dry run must not touch the file');

        $firstBatch = new CommandTester(new PurgeCommand('audit:purge', new FileAuditReader((string)$this->auditFile)));
        static::assertSame(0, $firstBatch->execute(['--before' => $before, '--batch-size' => '2', '--force' => true]));
        static::assertStringContainsString('purged 2 transaction(s)', $firstBatch->getDisplay());
        static::assertStringContainsString('run the command again', $firstBatch->getDisplay());
        static::assertCount(2, $this->readLines());

        $secondBatch = new CommandTester(new PurgeCommand('audit:purge', new FileAuditReader((string)$this->auditFile)));
        static::assertSame(0, $secondBatch->execute(['--before' => $before, '--batch-size' => '2', '--force' => true]));
        static::assertStringContainsString('purged 2 transaction(s)', $secondBatch->getDisplay());
        static::assertStringNotContainsString('run the command again', $secondBatch->getDisplay());
        static::assertSame([], $this->readLines());

        /* the purge keeps the inode it truncated, which is what the flock contract with the writer rests on */
        static::assertFileDoesNotExist($this->auditFile . '.purge');
        static::assertFileExists((string)$this->auditFile);
    }

    protected function tearDown(): void
    {
        $this->environment?->close();
        $this->environment = null;

        foreach ([$this->auditFile, $this->auditFile . '.purge'] as $file) {
            if (null !== $this->auditFile && null !== $file && true === \file_exists($file)) {
                \unlink($file);
            }
        }

        $this->auditFile = null;

        parent::tearDown();
    }

    private function createEnvironment(string $environmentVariable): AuditIntegrationEnvironment
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

        $this->auditFile = \sys_get_temp_dir() . '/audit_commands_' . \uniqid() . '.log';

        return $this->environment = new AuditIntegrationEnvironment(
            $sourceConnection,
            $auditConnection,
            extraStorages: [new FileStorage($this->auditFile, null)],
        );
    }

    /** Four transactions: an insert, a collection addition, an update and a delete. */
    private function writeAuditTrail(AuditIntegrationEnvironment $environment): int
    {
        $related = (new RelatedSubject())->setLabel('commands');
        $environment->sourceEntityManager->persist($related);
        $environment->sourceEntityManager->flush();

        $subject = (new AuditedSubject())->setName('commands')->setSecret('s')->setModified('m');
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $subject->getRelatedSubjects()->add($related);
        $environment->sourceEntityManager->flush();

        $subject->setName('commands-updated');
        $environment->sourceEntityManager->flush();

        $subjectId = (int)$subject->getId();

        $environment->sourceEntityManager->remove($subject);
        $environment->sourceEntityManager->flush();

        return $subjectId;
    }

    /** @return array<int, array<string, mixed>> */
    private function readLines(): array
    {
        static::assertNotNull($this->auditFile);

        $contents = \file_get_contents($this->auditFile);
        static::assertNotFalse($contents);

        return \array_map(
            static fn(string $line) => \json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            \array_values(\array_filter(\explode(\PHP_EOL, \trim($contents)))),
        );
    }
}
