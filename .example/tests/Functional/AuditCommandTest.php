<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Test\Functional;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Audit\Command\Audit\PurgeCommand;
use PrecisionSoft\Doctrine\Audit\Command\Audit\ReadCommand;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\UpdateCommand;
use PrecisionSoft\Doctrine\Audit\Example\CatalogueKernel;
use PrecisionSoft\Doctrine\Audit\Example\Entity\Product;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueDatabase;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The four commands the bundle gives an application, driven over a trail a real flush wrote. */
final class AuditCommandTest extends CatalogueTestCase
{
    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testTheSchemaUpdateCommandHasNothingToDoOnAFreshTrail(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $command = $this->getContainerService(CatalogueKernel::SCHEMA_UPDATE_COMMAND);
        static::assertInstanceOf(UpdateCommand::class, $command);

        $commandTester = new CommandTester($command);

        static::assertSame(0, $commandTester->execute([]));

        /* the trail was just created from the same metadata, so a second look finds no drift - the deployment check */
        static::assertStringNotContainsString('ALTER TABLE', $commandTester->getDisplay());
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testReadFindsAProductsHistoryAndPagesThroughIt(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $product = $catalogue->createProduct('chisel', 1900);
        $catalogue->reprice($product, 2100);

        /* the orm clears a removed entity's generated identifier, so the trail's own key has to be read before the delete */
        $productId = (int)$product->getId();

        $catalogue->retire($product);

        $byIdentity = $this->createReadCommandTester();

        static::assertSame(0, $byIdentity->execute([
            '--entity-class' => Product::class,
            '--identity' => [\sprintf('id=%d', $productId)],
        ]));
        static::assertStringContainsString('3 transaction(s)', $byIdentity->getDisplay());

        $byOperation = $this->createReadCommandTester();

        static::assertSame(0, $byOperation->execute([
            '--entity-class' => Product::class,
            '--operation' => 'delete',
        ]));
        static::assertStringContainsString('1 transaction(s)', $byOperation->getDisplay());

        $firstPage = $this->createReadCommandTester();

        static::assertSame(0, $firstPage->execute(['--limit' => '1']));

        $matches = [];
        static::assertSame(1, \preg_match('/--cursor=(\S+)/', $firstPage->getDisplay(), $matches));

        $cursor = $matches[1] ?? null;
        static::assertIsString($cursor);

        $secondPage = $this->createReadCommandTester();

        static::assertSame(0, $secondPage->execute(['--limit' => '1', '--cursor' => $cursor]));
        static::assertStringContainsString('1 transaction(s)', $secondPage->getDisplay());
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testPurgeRetiresTheJsonlTrailOneBatchAtATime(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $product = $catalogue->createProduct('spokeshave', 3300);
        $catalogue->reprice($product, 3500);
        $catalogue->reprice($product, 3700);
        $catalogue->reprice($product, 3900);

        static::assertCount(4, $this->readJsonLines());

        $before = (new DateTimeImmutable('+1 second'))->format(DateTimeInterface::ATOM);

        $dryRun = $this->createPurgeCommandTester();

        static::assertSame(0, $dryRun->execute(['--before' => $before, '--batch-size' => '2']));
        static::assertStringContainsString('nothing was purged', $dryRun->getDisplay());
        static::assertCount(4, $this->readJsonLines(), 'a dry run reports and changes nothing');

        $firstBatch = $this->createPurgeCommandTester();

        static::assertSame(0, $firstBatch->execute(['--before' => $before, '--batch-size' => '2', '--force' => true]));
        static::assertStringContainsString('purged 2 transaction(s)', $firstBatch->getDisplay());
        static::assertStringContainsString('run the command again', $firstBatch->getDisplay());
        static::assertCount(2, $this->readJsonLines());

        $secondBatch = $this->createPurgeCommandTester();

        static::assertSame(0, $secondBatch->execute(['--before' => $before, '--batch-size' => '2', '--force' => true]));
        static::assertStringNotContainsString('run the command again', $secondBatch->getDisplay());
        static::assertSame([], $this->readJsonLines());

        /* retention only touched the jsonl storage: the doctrine trail of the same flushes is untouched */
        static::assertCount(4, $this->readAuditRows('product'));
    }

    private function createReadCommandTester(): CommandTester
    {
        $command = $this->getContainerService(CatalogueKernel::AUDIT_READ_COMMAND);

        static::assertInstanceOf(ReadCommand::class, $command);

        return new CommandTester($command);
    }

    private function createPurgeCommandTester(): CommandTester
    {
        $command = $this->getContainerService(CatalogueKernel::AUDIT_PURGE_COMMAND);

        static::assertInstanceOf(PurgeCommand::class, $command);

        return new CommandTester($command);
    }
}
