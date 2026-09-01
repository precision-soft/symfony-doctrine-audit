<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeRequest;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\FileAuditReader;

/** @internal */
final class FileAuditReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'audit-reader-');

        if (false === $file) {
            throw new Exception('temp file failed');
        }

        $this->file = $file;

        $this->write([
            $this->record('2024-01-01 10:00:00', 'alice', 'insert', 10),
            $this->record('2025-01-01 10:00:00', 'bob', 'update', 20),
            $this->record('2026-01-01 10:00:00', 'alice', 'delete', 30),
        ]);
    }

    protected function tearDown(): void
    {
        @\unlink($this->file);
        @\unlink($this->file . '.purge');
    }

    public function testReadFiltersIdentityUserOperationAndPaginates(): void
    {
        $reader = new FileAuditReader($this->file);
        $page = $reader->read(new AuditQuery(username: 'alice', limit: 1));

        static::assertCount(1, $page->getTransactions());
        static::assertNotNull($page->getNextCursor());

        $second = $reader->read(new AuditQuery(username: 'alice', limit: 1, cursor: $page->getNextCursor()));

        static::assertCount(1, $second->getTransactions());
        static::assertSame('2026-01-01 10:00:00', $second->getTransactions()[0]['date']);
        static::assertNull($second->getNextCursor());

        $identity = $reader->read(new AuditQuery(
            entityClass: 'App\\Order',
            identity: ['id' => 20],
            operation: Operation::Update,
        ));

        static::assertSame('bob', $identity->getTransactions()[0]['username']);
    }

    public function testReadReturnsAnEmptyPageForAMissingFile(): void
    {
        $reader = new FileAuditReader($this->file . '-missing');

        $page = $reader->read(new AuditQuery());

        static::assertSame([], $page->getTransactions());
        static::assertNull($page->getNextCursor());
    }

    public function testReadFiltersByTransactionRange(): void
    {
        $reader = new FileAuditReader($this->file);

        $page = $reader->read(new AuditQuery(
            from: new DateTimeImmutable('2024-06-01'),
            until: new DateTimeImmutable('2025-06-01'),
        ));

        static::assertCount(1, $page->getTransactions());
        static::assertSame('bob', $page->getTransactions()[0]['username']);
    }

    /** `from` is inclusive and `until` is exclusive, which is what makes consecutive windows tile without overlap. */
    public function testTheTransactionRangeBoundsAreInclusiveThenExclusive(): void
    {
        $reader = new FileAuditReader($this->file);

        static::assertCount(
            1,
            $reader->read(new AuditQuery(
                from: new DateTimeImmutable('2025-01-01 10:00:00'),
                until: new DateTimeImmutable('2026-01-01 10:00:00'),
            ))->getTransactions(),
            'the record sitting exactly on `from` is in, the one sitting exactly on `until` is out',
        );
        static::assertCount(
            0,
            $reader->read(new AuditQuery(
                from: new DateTimeImmutable('2025-01-01 10:00:01'),
                until: new DateTimeImmutable('2026-01-01 10:00:00'),
            ))->getTransactions(),
        );
    }

    public function testReadRejectsACursorWithTrailingGarbage(): void
    {
        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid audit cursor');

        $reader->read(new AuditQuery(cursor: \base64_encode('1oops')));
    }

    public function testReadRejectsACursorWithLeadingGarbage(): void
    {
        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid audit cursor');

        $reader->read(new AuditQuery(cursor: \base64_encode('oops1')));
    }

    public function testAPurgeBoundaryIsExclusive(): void
    {
        $reader = new FileAuditReader($this->file);

        static::assertSame(
            1,
            $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01 10:00:00')))->getMatchedTransactions(),
            'the record sitting exactly on `before` is kept',
        );
        static::assertSame(
            2,
            $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01 10:00:01')))->getMatchedTransactions(),
        );
    }

    public function testReadMatchesIdentityAgainstTheOldAndNewShapeOfAnUpdatedColumn(): void
    {
        $this->write([
            \json_encode([
                'username' => 'carol',
                'date' => '2025-02-01 10:00:00',
                'entities' => [[
                    'operation' => 'update',
                    'class' => 'App\\Order',
                    'columns' => ['id' => 42, 'name' => ['old' => 'before', 'new' => 'after']],
                ]],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $reader = new FileAuditReader($this->file);

        static::assertCount(
            1,
            $reader->read(new AuditQuery(entityClass: 'App\\Order', identity: ['name' => 'after']))->getTransactions(),
        );
        static::assertCount(
            0,
            $reader->read(new AuditQuery(entityClass: 'App\\Order', identity: ['name' => 'before']))->getTransactions(),
        );
    }

    public function testReadFindsATransactionThatOnlyCarriesCollectionChanges(): void
    {
        $this->writeCollectionRecord();

        $reader = new FileAuditReader($this->file);

        static::assertCount(1, $reader->read(new AuditQuery())->getTransactions());
        static::assertCount(1, $reader->read(new AuditQuery(entityClass: 'App\\Order'))->getTransactions());
        static::assertCount(1, $reader->read(new AuditQuery(entityClass: 'App\\Tag'))->getTransactions());
        static::assertCount(0, $reader->read(new AuditQuery(entityClass: 'App\\Other'))->getTransactions());
    }

    public function testACollectionChangeIsFoundByTheIdentityOfEitherSide(): void
    {
        $this->writeCollectionRecord();

        $reader = new FileAuditReader($this->file);

        static::assertCount(
            1,
            $reader->read(new AuditQuery(entityClass: 'App\\Order', identity: ['id' => 7]))->getTransactions(),
            'the owner identity',
        );
        static::assertCount(
            1,
            $reader->read(new AuditQuery(entityClass: 'App\\Tag', identity: ['id' => 9]))->getTransactions(),
            'an added target identity',
        );
        static::assertCount(
            1,
            $reader->read(new AuditQuery(entityClass: 'App\\Tag', identity: ['id' => 4]))->getTransactions(),
            'a removed target identity',
        );
        static::assertCount(
            0,
            $reader->read(new AuditQuery(entityClass: 'App\\Tag', identity: ['id' => 99]))->getTransactions(),
        );
        static::assertCount(
            0,
            $reader->read(new AuditQuery(entityClass: 'App\\Order', identity: ['id' => 99]))->getTransactions(),
        );
    }

    /** A collection change carries no operation, so asking for one is asking about entity rows. */
    public function testAnOperationFilterExcludesCollectionChanges(): void
    {
        $this->writeCollectionRecord();

        $reader = new FileAuditReader($this->file);

        static::assertCount(
            0,
            $reader->read(new AuditQuery(entityClass: 'App\\Order', operation: Operation::Update))->getTransactions(),
        );
    }

    public function testACollectionChangeOfAnUnexpectedShapeMatchesNothing(): void
    {
        $this->write([
            \json_encode([
                'username' => 'dave',
                'date' => '2025-03-01 10:00:00',
                'entities' => [],
                'collections' => ['not-a-row', ['owner_class' => 'App\\Order', 'owner_identifier' => 'not-an-array']],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $reader = new FileAuditReader($this->file);

        static::assertCount(
            0,
            $reader->read(new AuditQuery(entityClass: 'App\\Order', identity: ['id' => 7]))->getTransactions(),
        );
        static::assertCount(1, $reader->read(new AuditQuery(entityClass: 'App\\Order'))->getTransactions());
    }

    private function writeCollectionRecord(): void
    {
        $this->write([
            \json_encode([
                'username' => 'dave',
                'date' => '2025-03-01 10:00:00',
                'entities' => [],
                'collections' => [[
                    'owner_class' => 'App\\Order',
                    'owner_identifier' => ['id' => 7],
                    'field' => 'tags',
                    'target_class' => 'App\\Tag',
                    'added' => [['id' => 9]],
                    'removed' => [['id' => 4]],
                ]],
            ], \JSON_THROW_ON_ERROR),
        ]);
    }

    public function testReadRejectsAnInvalidCursor(): void
    {
        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid audit cursor');

        $reader->read(new AuditQuery(cursor: \base64_encode('not-a-line')));
    }

    public function testReadRejectsACursorThatIsNotBase64(): void
    {
        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid audit cursor');

        $reader->read(new AuditQuery(cursor: '!!!'));
    }

    public function testReadRejectsAMalformedRecord(): void
    {
        $this->write(['{not json']);

        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is not valid json');

        $reader->read(new AuditQuery());
    }

    public function testReadRejectsARecordThatIsNotAnObject(): void
    {
        $this->write(['42']);

        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('is not an object');

        $reader->read(new AuditQuery());
    }

    public function testReadRejectsAnUnparsableDate(): void
    {
        $this->write([\json_encode(['username' => 'eve', 'date' => 'not-a-date'], \JSON_THROW_ON_ERROR)]);

        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('unparsable date');

        $reader->read(new AuditQuery(from: new DateTimeImmutable('2020-01-01')));
    }

    public function testReadTreatsARecordWithoutADateAsUndated(): void
    {
        $this->write([\json_encode(['username' => 'eve', 'entities' => []], \JSON_THROW_ON_ERROR)]);

        $reader = new FileAuditReader($this->file);

        static::assertCount(1, $reader->read(new AuditQuery())->getTransactions());
        static::assertCount(
            0,
            $reader->read(new AuditQuery(from: new DateTimeImmutable('2020-01-01')))->getTransactions(),
        );
    }

    public function testPurgeIsDryRunByDefaultAndDeletesWholeTransactionsInBatches(): void
    {
        $reader = new FileAuditReader($this->file);
        $request = new PurgeRequest(new DateTimeImmutable('2025-06-01'), 1);

        $dryRun = $reader->purge($request);

        static::assertSame(1, $dryRun->getMatchedTransactions());
        static::assertSame(0, $dryRun->getPurgedTransactions());
        static::assertTrue($dryRun->hasMore());
        static::assertCount(3, $reader->read(new AuditQuery())->getTransactions());

        $result = $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-06-01'), 1, false));

        static::assertSame(1, $result->getMatchedTransactions());
        static::assertSame(1, $result->getPurgedTransactions());
        static::assertTrue($result->hasMore());
        static::assertCount(2, $reader->read(new AuditQuery())->getTransactions());
    }

    /** A dry run must report `hasMore` for records beyond the batch only, never for the ones it just counted. */
    public function testADryRunOverASingleBatchReportsNothingMore(): void
    {
        $this->write([$this->record('2020-01-01 10:00:00', 'alice', 'insert', 10)]);

        $reader = new FileAuditReader($this->file);

        $dryRun = $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01')));

        static::assertSame(1, $dryRun->getMatchedTransactions());
        static::assertFalse($dryRun->hasMore());

        $result = $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01'), dryRun: false));

        static::assertSame(1, $result->getPurgedTransactions());
        static::assertFalse($result->hasMore());
        static::assertSame([], $reader->read(new AuditQuery())->getTransactions());
    }

    public function testPurgeKeepsTheRecordsItDoesNotMatchByteForByte(): void
    {
        $kept = [
            $this->record('2026-01-01 10:00:00', 'alice', 'delete', 30),
            $this->record('2027-01-01 10:00:00', 'bob', 'insert', 40),
        ];
        $this->write([$this->record('2020-01-01 10:00:00', 'old', 'insert', 1), ...$kept]);

        $reader = new FileAuditReader($this->file);
        $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01'), dryRun: false));

        static::assertSame(\implode("\n", $kept) . "\n", \file_get_contents($this->file));
        static::assertFileDoesNotExist($this->file . '.purge');
    }

    public function testPurgeHandlesAFileWithoutATrailingNewline(): void
    {
        \file_put_contents(
            $this->file,
            $this->record('2020-01-01 10:00:00', 'old', 'insert', 1)
            . "\n"
            . $this->record('2026-01-01 10:00:00', 'alice', 'delete', 30),
        );

        $reader = new FileAuditReader($this->file);
        $result = $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01'), dryRun: false));

        static::assertSame(1, $result->getPurgedTransactions());
        static::assertCount(1, $reader->read(new AuditQuery())->getTransactions());
    }

    public function testPurgeMatchingNothingLeavesTheFileAlone(): void
    {
        $before = \file_get_contents($this->file);

        $reader = new FileAuditReader($this->file);
        $result = $reader->purge(new PurgeRequest(new DateTimeImmutable('2000-01-01'), dryRun: false));

        static::assertSame(0, $result->getMatchedTransactions());
        static::assertSame(0, $result->getPurgedTransactions());
        static::assertFalse($result->hasMore());
        static::assertSame($before, \file_get_contents($this->file));
        static::assertFileDoesNotExist($this->file . '.purge');
    }

    public function testPurgeOfAMissingFileMatchesNothing(): void
    {
        $reader = new FileAuditReader($this->file . '-missing');

        $result = $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-01-01'), dryRun: false));

        static::assertSame(0, $result->getMatchedTransactions());
        static::assertSame(0, $result->getPurgedTransactions());
        static::assertFalse($result->hasMore());
    }

    public function testPurgeRefusesToRunWhileAnUnfinishedPurgeFileIsAround(): void
    {
        \file_put_contents($this->file . '.purge', 'leftover');

        $reader = new FileAuditReader($this->file);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('did not finish');

        $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-06-01'), dryRun: false));
    }

    public function testADryRunIgnoresAnUnfinishedPurgeFile(): void
    {
        \file_put_contents($this->file . '.purge', 'leftover');

        $reader = new FileAuditReader($this->file);

        static::assertSame(
            2,
            $reader->purge(new PurgeRequest(new DateTimeImmutable('2025-06-01')))->getMatchedTransactions(),
        );
    }

    /** @param string[] $records */
    private function write(array $records): void
    {
        \file_put_contents($this->file, \implode("\n", $records) . "\n");
    }

    private function record(string $date, string $username, string $operation, int $id): string
    {
        return \json_encode([
            'username' => $username,
            'date' => $date,
            'entities' => [[
                'operation' => $operation,
                'class' => 'App\\Order',
                'columns' => ['id' => $id],
            ]],
        ], \JSON_THROW_ON_ERROR);
    }
}
