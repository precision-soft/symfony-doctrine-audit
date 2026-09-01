<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Command\Audit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Command\Audit\PurgeCommand;
use PrecisionSoft\Doctrine\Audit\Contract\AuditPurgerInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeRequest;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeResult;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use Symfony\Component\Console\Tester\CommandTester;

/** @internal */
final class PurgeCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testItIsADryRunWithoutForce(): void
    {
        /** @var AuditPurgerInterface&MockInterface $auditPurger */
        $auditPurger = Mockery::mock(AuditPurgerInterface::class);
        $auditPurger->shouldReceive('purge')
            ->once()
            ->with(Mockery::on(function (PurgeRequest $purgeRequest): bool {
                static::assertSame('2025-01-01', $purgeRequest->getBefore()->format('Y-m-d'));
                static::assertSame(PurgeRequest::DEFAULT_BATCH_SIZE, $purgeRequest->getBatchSize());
                static::assertTrue($purgeRequest->getDryRun());

                return true;
            }))
            ->andReturn(new PurgeResult(3, 0, false));

        $commandTester = new CommandTester(new PurgeCommand('audit:purge', $auditPurger));

        static::assertSame(0, $commandTester->execute(['--before' => '2025-01-01']));

        $display = $commandTester->getDisplay();

        static::assertStringContainsString('nothing was purged', $display);
        static::assertStringContainsString('--force', $display);
    }

    public function testForcePurgesAndReportsTheRemainingBatches(): void
    {
        /** @var AuditPurgerInterface&MockInterface $auditPurger */
        $auditPurger = Mockery::mock(AuditPurgerInterface::class);
        $auditPurger->shouldReceive('purge')
            ->once()
            ->with(Mockery::on(function (PurgeRequest $purgeRequest): bool {
                static::assertSame(10, $purgeRequest->getBatchSize());
                static::assertFalse($purgeRequest->getDryRun());

                return true;
            }))
            ->andReturn(new PurgeResult(10, 10, true));

        $commandTester = new CommandTester(new PurgeCommand('audit:purge', $auditPurger));

        static::assertSame(0, $commandTester->execute([
            '--before' => '2025-01-01',
            '--batch-size' => '10',
            '--force' => true,
        ]));

        $display = $commandTester->getDisplay();

        static::assertStringContainsString('purged 10 transaction(s)', $display);
        static::assertStringContainsString('run the command again', $display);
    }

    public function testForceStaysQuietWhenNothingIsLeft(): void
    {
        /** @var AuditPurgerInterface&MockInterface $auditPurger */
        $auditPurger = Mockery::mock(AuditPurgerInterface::class);
        $auditPurger->shouldReceive('purge')->once()->andReturn(new PurgeResult(2, 2, false));

        $commandTester = new CommandTester(new PurgeCommand('audit:purge', $auditPurger));

        static::assertSame(0, $commandTester->execute(['--before' => '2025-01-01', '--force' => true]));
        static::assertStringNotContainsString('run the command again', $commandTester->getDisplay());
    }

    public function testBeforeIsMandatory(): void
    {
        /** @var AuditPurgerInterface&MockInterface $auditPurger */
        $auditPurger = Mockery::mock(AuditPurgerInterface::class);
        $auditPurger->shouldNotReceive('purge');

        $commandTester = new CommandTester(new PurgeCommand('audit:purge', $auditPurger));

        static::assertSame(1, $commandTester->execute([]));
        static::assertStringContainsString('is mandatory', $commandTester->getDisplay());
    }

    public function testItFailsOnABatchSizeOutsideTheAllowedRange(): void
    {
        /** @var AuditPurgerInterface&MockInterface $auditPurger */
        $auditPurger = Mockery::mock(AuditPurgerInterface::class);
        $auditPurger->shouldNotReceive('purge');

        $commandTester = new CommandTester(new PurgeCommand('audit:purge', $auditPurger));

        static::assertSame(1, $commandTester->execute(['--before' => '2025-01-01', '--batch-size' => '0']));
        static::assertStringContainsString('purge batch size must be between', $commandTester->getDisplay());
    }

    public function testItFailsWhenThePurgerThrows(): void
    {
        /** @var AuditPurgerInterface&MockInterface $auditPurger */
        $auditPurger = Mockery::mock(AuditPurgerInterface::class);
        $auditPurger->shouldReceive('purge')->andThrow(new Exception('could not lock audit file'));

        $commandTester = new CommandTester(new PurgeCommand('audit:purge', $auditPurger));

        static::assertSame(1, $commandTester->execute(['--before' => '2025-01-01', '--force' => true]));
        static::assertStringContainsString('could not lock audit file', $commandTester->getDisplay());
    }
}
