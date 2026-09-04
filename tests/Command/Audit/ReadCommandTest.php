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
use PrecisionSoft\Doctrine\Audit\Command\Audit\ReadCommand;
use PrecisionSoft\Doctrine\Audit\Contract\AuditReaderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditPage;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\FileAuditReader;
use Symfony\Component\Console\Tester\CommandTester;

/** @internal */
final class ReadCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testItRendersTheTransactionsAndTheNextCursor(): void
    {
        $auditPage = new AuditPage(
            [[
                'username' => 'alice',
                'date' => '2025-01-01 10:00:00',
                'entities' => [['operation' => 'update', 'class' => 'App\\Order']],
                'collections' => [[
                    'owner_class' => 'App\\Order',
                    'field' => 'tags',
                    'added' => [['id' => 1]],
                    'removed' => [],
                ]],
            ]],
            'Mg==',
        );

        $commandTester = new CommandTester($this->createCommand($auditPage));

        static::assertSame(0, $commandTester->execute([]));

        $display = $commandTester->getDisplay();

        static::assertStringContainsString('alice', $display);
        static::assertStringContainsString('update App\\Order', $display);
        static::assertStringContainsString('App\\Order::tags +1 -0', $display);
        static::assertStringContainsString('1 transaction(s)', $display);
        static::assertStringContainsString('--cursor=Mg==', $display);
    }

    public function testItSaysNothingAboutAPageWithoutANextCursor(): void
    {
        $commandTester = new CommandTester($this->createCommand(new AuditPage([], null)));

        static::assertSame(0, $commandTester->execute([]));
        static::assertStringContainsString('0 transaction(s)', $commandTester->getDisplay());
        static::assertStringNotContainsString('--cursor=', $commandTester->getDisplay());
    }

    public function testItPassesEveryOptionThroughToTheQuery(): void
    {
        /** @var AuditReaderInterface&MockInterface $auditReader */
        $auditReader = Mockery::mock(AuditReaderInterface::class);
        $auditReader->shouldReceive('read')
            ->once()
            ->with(Mockery::on(function (AuditQuery $auditQuery): bool {
                static::assertSame('App\\Order', $auditQuery->getEntityClass());
                static::assertSame(['id' => 42, 'paid' => true, 'note' => null, 'ratio' => 1.5, 'name' => 'x'], $auditQuery->getIdentity());
                static::assertNotNull($auditQuery->getFrom());
                static::assertSame('2024-01-01', $auditQuery->getFrom()->format('Y-m-d'));
                static::assertNotNull($auditQuery->getUntil());
                static::assertSame('2025-01-01', $auditQuery->getUntil()->format('Y-m-d'));
                static::assertSame('alice', $auditQuery->getUsername());
                static::assertSame(Operation::Update, $auditQuery->getOperation());
                static::assertSame(7, $auditQuery->getLimit());
                static::assertSame('Mg==', $auditQuery->getCursor());

                return true;
            }))
            ->andReturn(new AuditPage([], null));

        $commandTester = new CommandTester(new ReadCommand('audit:read', $auditReader));

        static::assertSame(0, $commandTester->execute([
            '--entity-class' => 'App\\Order',
            '--identity' => ['id=42', 'paid=true', 'note=null', 'ratio=1.5', 'name=x'],
            '--from' => '2024-01-01',
            '--until' => '2025-01-01',
            '--username' => 'alice',
            '--operation' => 'update',
            '--limit' => '7',
            '--cursor' => 'Mg==',
        ]));
    }

    public function testItDefaultsToAnUnfilteredQuery(): void
    {
        /** @var AuditReaderInterface&MockInterface $auditReader */
        $auditReader = Mockery::mock(AuditReaderInterface::class);
        $auditReader->shouldReceive('read')
            ->once()
            ->with(Mockery::on(function (AuditQuery $auditQuery): bool {
                static::assertNull($auditQuery->getEntityClass());
                static::assertSame([], $auditQuery->getIdentity());
                static::assertNull($auditQuery->getFrom());
                static::assertNull($auditQuery->getUntil());
                static::assertNull($auditQuery->getUsername());
                static::assertNull($auditQuery->getOperation());
                static::assertSame(AuditQuery::DEFAULT_LIMIT, $auditQuery->getLimit());
                static::assertNull($auditQuery->getCursor());

                return true;
            }))
            ->andReturn(new AuditPage([], null));

        $commandTester = new CommandTester(new ReadCommand('audit:read', $auditReader));

        static::assertSame(0, $commandTester->execute([]));
    }

    public function testItSkipsRecordShapesItCannotRender(): void
    {
        $auditPage = new AuditPage(
            [[
                'username' => 'alice',
                'date' => '2025-01-01 10:00:00',
                'entities' => 'not-a-list',
                'collections' => ['not-a-row', ['owner_class' => 'App\\Order', 'field' => 'tags', 'added' => 'nope']],
            ]],
            null,
        );

        $commandTester = new CommandTester($this->createCommand($auditPage));

        static::assertSame(0, $commandTester->execute([]));

        $display = $commandTester->getDisplay();

        static::assertStringContainsString('alice', $display);
        static::assertStringContainsString('App\\Order::tags +0 -0', $display);
    }

    public function testItFailsOnAnUnknownOperation(): void
    {
        $commandTester = new CommandTester($this->createCommand(new AuditPage([], null)));

        static::assertSame(1, $commandTester->execute(['--operation' => 'truncate']));
        static::assertStringContainsString('must be one of', $commandTester->getDisplay());
    }

    public function testItFailsOnAnIdentityWithoutAnEqualsSign(): void
    {
        $commandTester = new CommandTester($this->createCommand(new AuditPage([], null)));

        static::assertSame(1, $commandTester->execute(['--identity' => ['id']]));
        static::assertStringContainsString('field=value', $commandTester->getDisplay());
    }

    public function testItFailsOnAnIdentityWithoutAField(): void
    {
        $commandTester = new CommandTester($this->createCommand(new AuditPage([], null)));

        static::assertSame(1, $commandTester->execute(['--identity' => ['=42']]));
        static::assertStringContainsString('field=value', $commandTester->getDisplay());
    }

    public function testItFailsOnAnUnparsableDate(): void
    {
        $commandTester = new CommandTester($this->createCommand(new AuditPage([], null)));

        static::assertSame(1, $commandTester->execute(['--from' => 'yesteryear']));
        static::assertStringContainsString('parsable date', $commandTester->getDisplay());
    }

    public function testItFailsOnANonIntegerLimit(): void
    {
        $commandTester = new CommandTester($this->createCommand(new AuditPage([], null)));

        static::assertSame(1, $commandTester->execute(['--limit' => 'many']));
        static::assertStringContainsString('positive integer', $commandTester->getDisplay());
    }

    public function testItFailsWhenTheReaderThrows(): void
    {
        /** @var AuditReaderInterface&MockInterface $auditReader */
        $auditReader = Mockery::mock(AuditReaderInterface::class);
        $auditReader->shouldReceive('read')->andThrow(new Exception('storage is gone'));

        $commandTester = new CommandTester(new ReadCommand('audit:read', $auditReader));

        static::assertSame(1, $commandTester->execute([]));
        static::assertStringContainsString('storage is gone', $commandTester->getDisplay());
    }

    public function testAQuotedIdentityKeepsAStringThatLooksNumeric(): void
    {
        $file = $this->writeRecord(['code' => '007', 'id' => 42]);

        try {
            $quoted = new CommandTester(new ReadCommand('audit:read', new FileAuditReader($file)));

            static::assertSame(0, $quoted->execute(['--identity' => ['code="007"']]));
            static::assertStringContainsString('1 transaction(s)', $quoted->getDisplay());

            /* unquoted keeps the numeric cast, which is what finds an integer column */
            $unquoted = new CommandTester(new ReadCommand('audit:read', new FileAuditReader($file)));

            static::assertSame(0, $unquoted->execute(['--identity' => ['id=42']]));
            static::assertStringContainsString('1 transaction(s)', $unquoted->getDisplay());

            /* and unquoted `007` is the integer 7, so it must not match the string column */
            $lossy = new CommandTester(new ReadCommand('audit:read', new FileAuditReader($file)));

            static::assertSame(0, $lossy->execute(['--identity' => ['code=007']]));
            static::assertStringContainsString('0 transaction(s)', $lossy->getDisplay());
        } finally {
            @\unlink($file);
        }
    }

    public function testOnlyABalancedPairOfQuotesKeepsTheValueAString(): void
    {
        /** @var AuditReaderInterface&MockInterface $auditReader */
        $auditReader = Mockery::mock(AuditReaderInterface::class);
        $auditReader->shouldReceive('read')
            ->once()
            ->with(Mockery::on(function (AuditQuery $auditQuery): bool {
                static::assertSame(
                    [
                        'empty' => '',
                        'unterminated' => '"007',
                        'unopened' => '007"',
                        'quoted' => '7',
                    ],
                    $auditQuery->getIdentity(),
                );

                return true;
            }))
            ->andReturn(new AuditPage([], null));

        $commandTester = new CommandTester(new ReadCommand('audit:read', $auditReader));

        static::assertSame(0, $commandTester->execute([
            '--identity' => ['empty=""', 'unterminated="007', 'unopened=007"', 'quoted="7"'],
        ]));
    }

    /** @param array<string, scalar> $columns */
    private function writeRecord(array $columns): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'audit-read-');

        if (false === $file) {
            throw new Exception('temp file failed');
        }

        \file_put_contents($file, \json_encode([
            'username' => 'alice',
            'date' => '2025-01-01 10:00:00',
            'entities' => [[
                'operation' => 'update',
                'class' => 'App\\Order',
                'columns' => $columns,
            ]],
        ], \JSON_THROW_ON_ERROR) . \PHP_EOL);

        return $file;
    }

    private function createCommand(AuditPage $auditPage): ReadCommand
    {
        /** @var AuditReaderInterface&MockInterface $auditReader */
        $auditReader = Mockery::mock(AuditReaderInterface::class);
        $auditReader->shouldReceive('read')->andReturn($auditPage);

        return new ReadCommand('audit:read', $auditReader);
    }
}
