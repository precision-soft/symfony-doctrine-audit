<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage;

use DateTimeImmutable;
use PrecisionSoft\Doctrine\Audit\Contract\AuditPurgerInterface;
use PrecisionSoft\Doctrine\Audit\Contract\AuditReaderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditPage;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeRequest;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeResult;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Trait\FileLockTrait;
use Throwable;

class FileAuditReader implements AuditReaderInterface, AuditPurgerInterface
{
    use FileLockTrait;

    protected const PURGE_SUFFIX = '.purge';
    protected const COPY_CHUNK_SIZE = 8192;

    public function __construct(protected readonly string $file) {}

    public function read(AuditQuery $query): AuditPage
    {
        $matches = [];
        $nextCursor = null;

        foreach ($this->readLines($this->decodeCursor($query->getCursor())) as $lineNumber => $line) {
            $transaction = $this->decode($line);

            if (false === $this->matches($transaction, $query)) {
                continue;
            }

            if (\count($matches) === $query->getLimit()) {
                $nextCursor = $this->encodeCursor($lineNumber);

                break;
            }

            $matches[] = $transaction;
        }

        return new AuditPage($matches, $nextCursor);
    }

    /** Copying back rather than renaming keeps the inode, which is what makes the `flock()` contract with `FileStorage` hold. */
    public function purge(PurgeRequest $request): PurgeResult
    {
        if (false === \is_file($this->file)) {
            return new PurgeResult(0, 0, false);
        }

        $handle = $this->openLocked($this->file, 'c+b', \LOCK_EX);

        try {
            /* counting first costs a second scan only when there is work; in the steady state a scheduled purge finds nothing and never writes, nor creates the file below */
            [$matched, $hasMore] = $this->partition($handle, null, $request);

            if (true === $request->getDryRun() || 0 === $matched) {
                return new PurgeResult($matched, 0, $hasMore);
            }

            $purgeFile = $this->file . static::PURGE_SUFFIX;

            if (true === \is_file($purgeFile)) {
                throw new Exception(
                    \sprintf(
                        'a previous purge of `%s` did not finish; `%s` holds the records that must be kept, restore it before purging again',
                        $this->file,
                        $purgeFile,
                    ),
                );
            }

            $purgeHandle = $this->openLocked($purgeFile, 'w+b', \LOCK_EX);
            $keptSetComplete = false;

            try {
                [$matched, $hasMore] = $this->partition($handle, $purgeHandle, $request);

                $keptSetComplete = true;

                $this->copyBack($purgeHandle, $handle);
            } finally {
                $this->unlock($purgeHandle);

                /* only a complete kept set is worth restoring; leaving a partial one behind would turn the refusal above into an instruction to overwrite the intact audit file with a fraction of itself */
                if (false === $keptSetComplete) {
                    \unlink($purgeFile);
                }
            }

            if (false === \unlink($purgeFile)) {
                throw new Exception(
                    \sprintf('purged `%s` but could not remove `%s`, which now blocks the next purge', $this->file, $purgeFile),
                );
            }

            return new PurgeResult($matched, $matched, $hasMore);
        } finally {
            $this->unlock($handle);
        }
    }

    protected function getAuditFile(): string
    {
        return $this->file;
    }

    /**
     * `hasMore` counts only records purgeable *beyond* this batch, so it means the same on a dry run as on a real purge.
     *
     * @param resource $handle
     * @param resource|null $keptHandle
     * @return array{0: int, 1: bool}
     */
    protected function partition($handle, $keptHandle, PurgeRequest $request): array
    {
        \rewind($handle);

        $matched = 0;
        $hasMore = false;

        while (false !== ($line = \fgets($handle))) {
            $date = $this->getDate($this->decode($line));
            $purgeable = null !== $date && $date < $request->getBefore();

            if (true === $purgeable && $matched < $request->getBatchSize()) {
                ++$matched;

                continue;
            }

            if (true === $purgeable) {
                $hasMore = true;

                if (null === $keptHandle) {
                    break;
                }
            }

            if (null !== $keptHandle) {
                $this->writeAll($keptHandle, $line);
            }
        }

        return [$matched, $hasMore];
    }

    /**
     * @param resource $sourceHandle
     * @param resource $targetHandle
     */
    protected function copyBack($sourceHandle, $targetHandle): void
    {
        $this->flushAll($sourceHandle);

        \rewind($sourceHandle);
        \rewind($targetHandle);

        $written = 0;

        while (false === \feof($sourceHandle)) {
            $chunk = \fread($sourceHandle, static::COPY_CHUNK_SIZE);

            /* breaking here instead would truncate the audit file to whatever had been copied so far */
            if (false === $chunk) {
                throw new Exception(
                    \sprintf('could not read back the kept records of audit file `%s`', $this->file),
                );
            }

            $written += $this->writeAll($targetHandle, $chunk);
        }

        if (false === \ftruncate($targetHandle, $written)) {
            throw new Exception(\sprintf('could not truncate audit file `%s`', $this->file));
        }

        $this->flushAll($targetHandle);
    }

    /**
     * Lines before `$offset` are skipped without being decoded, so paging deeper into the file stays cheap.
     *
     * @return iterable<int, string>
     */
    protected function readLines(int $offset): iterable
    {
        if (false === \is_file($this->file)) {
            return;
        }

        $handle = $this->openLocked($this->file, 'rb', \LOCK_SH);

        try {
            $lineNumber = 0;

            while (false !== ($line = \fgets($handle))) {
                if ($lineNumber < $offset) {
                    ++$lineNumber;

                    continue;
                }

                yield $lineNumber++ => $line;
            }
        } finally {
            $this->unlock($handle);
        }
    }

    /** @param array<string, mixed> $transaction */
    protected function matches(array $transaction, AuditQuery $query): bool
    {
        $date = $this->getDate($transaction);

        if (null !== $query->getFrom() && (null === $date || $date < $query->getFrom())) {
            return false;
        }

        if (null !== $query->getUntil() && (null === $date || $date >= $query->getUntil())) {
            return false;
        }

        if (null !== $query->getUsername() && $query->getUsername() !== ($transaction['username'] ?? null)) {
            return false;
        }

        if (null === $query->getEntityClass() && null === $query->getOperation() && [] === $query->getIdentity()) {
            return true;
        }

        foreach ($this->getRecords($transaction, 'entities') as $entity) {
            if (true === $this->matchesEntity($entity, $query)) {
                return true;
            }
        }

        foreach ($this->getRecords($transaction, 'collections') as $collection) {
            if (true === $this->matchesCollection($collection, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A record is whatever the storage wrote, so every level is checked before it is walked.
     *
     * @param array<string, mixed> $parent
     * @return list<array<string, mixed>>
     */
    protected function getRecords(array $parent, string $key): array
    {
        $records = true === \is_array($parent[$key] ?? null) ? $parent[$key] : [];

        return \array_values(\array_filter($records, static fn(mixed $record): bool => true === \is_array($record)));
    }

    /**
     * @param array<string, mixed> $entity
     */
    protected function matchesEntity(array $entity, AuditQuery $query): bool
    {
        if (null !== $query->getEntityClass() && $query->getEntityClass() !== ($entity['class'] ?? null)) {
            return false;
        }

        if (null !== $query->getOperation() && $query->getOperation()->value !== ($entity['operation'] ?? null)) {
            return false;
        }

        $columns = true === \is_array($entity['columns'] ?? null) ? $entity['columns'] : [];

        foreach ($query->getIdentity() as $field => $value) {
            $stored = $columns[$field] ?? null;

            if (true === \is_array($stored) && true === \array_key_exists('new', $stored)) {
                $stored = $stored['new'];
            }

            if ($stored !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * A collection change matches when the queried class is either side of the association and the identity names one
     * of the entities involved. `operation` is entity-only, so setting it excludes collection changes entirely.
     *
     * @param array<string, mixed> $collection
     */
    protected function matchesCollection(array $collection, AuditQuery $query): bool
    {
        if (null !== $query->getOperation()) {
            return false;
        }

        $entityClass = $query->getEntityClass();
        $matchesOwner = null === $entityClass || $entityClass === ($collection['owner_class'] ?? null);
        $matchesTarget = null === $entityClass || $entityClass === ($collection['target_class'] ?? null);

        if (false === $matchesOwner && false === $matchesTarget) {
            return false;
        }

        $identity = $query->getIdentity();

        if ([] === $identity) {
            return true;
        }

        if (true === $matchesOwner && true === $this->matchesIdentifier($collection['owner_identifier'] ?? null, $identity)) {
            return true;
        }

        if (false === $matchesTarget) {
            return false;
        }

        foreach (['added', 'removed'] as $key) {
            foreach ($this->getRecords($collection, $key) as $identifier) {
                if (true === $this->matchesIdentifier($identifier, $identity)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, scalar|null> $identity */
    protected function matchesIdentifier(mixed $identifier, array $identity): bool
    {
        if (false === \is_array($identifier)) {
            return false;
        }

        foreach ($identity as $field => $value) {
            if (($identifier[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    protected function decode(string $line): array
    {
        try {
            $value = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new Exception(
                \sprintf('audit jsonl record of `%s` is not valid json', $this->file),
                0,
                $throwable,
            );
        }

        if (false === \is_array($value)) {
            throw new Exception(\sprintf('audit jsonl record of `%s` is not an object', $this->file));
        }

        return $value;
    }

    /** @param array<string, mixed> $transaction */
    protected function getDate(array $transaction): ?DateTimeImmutable
    {
        $date = $transaction['date'] ?? null;

        if (false === \is_string($date)) {
            return null;
        }

        try {
            return new DateTimeImmutable($date);
        } catch (Throwable $throwable) {
            throw new Exception(
                \sprintf('audit jsonl record of `%s` carries an unparsable date `%s`', $this->file, $date),
                0,
                $throwable,
            );
        }
    }

    protected function decodeCursor(?string $cursor): int
    {
        if (null === $cursor) {
            return 0;
        }

        $decoded = \base64_decode($cursor, true);

        /* `$` alone also accepts a trailing newline, and a value beyond PHP_INT_MAX saturates into an empty page that reads exactly like end of data */
        if (false === $decoded || 1 !== \preg_match('/^\d+$/D', $decoded) || $decoded !== (string)(int)$decoded) {
            throw new Exception('invalid audit cursor');
        }

        return (int)$decoded;
    }

    protected function encodeCursor(int $lineNumber): string
    {
        return \base64_encode((string)$lineNumber);
    }
}
