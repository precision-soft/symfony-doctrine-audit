<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage\Trait;

use PrecisionSoft\Doctrine\Audit\Exception\Exception;

/** The locked, partial-write-safe file io shared by the jsonl storage and the jsonl reader. */
trait FileLockTrait
{
    abstract protected function getAuditFile(): string;

    /**
     * @param int<0, 7> $lock
     * @return resource
     */
    protected function openLocked(string $file, string $mode, int $lock)
    {
        \error_clear_last();

        /* the caller turns `false` into the exception below, so php's own warning would only duplicate it - the reason it carries is kept in the message */
        $handle = @$this->openFile($file, $mode);

        if (false === $handle) {
            throw new Exception(\sprintf('could not open audit file `%s`%s', $file, $this->getLastErrorSuffix()));
        }

        if (false === $this->lockFile($handle, $lock)) {
            \fclose($handle);

            throw new Exception(\sprintf('could not lock audit file `%s`', $file));
        }

        return $handle;
    }

    /** @param resource $handle */
    protected function unlock($handle): void
    {
        \flock($handle, \LOCK_UN);
        \fclose($handle);
    }

    /**
     * @param resource $handle
     * @return int<0, max> the number of bytes written
     */
    protected function writeAll($handle, string $contents): int
    {
        $remaining = $contents;

        while (\strlen($remaining) > 0) {
            $written = $this->writeFile($handle, $remaining);

            if (false === $written || 0 === $written) {
                throw new Exception(\sprintf('could not write audit file `%s`', $this->getAuditFile()));
            }

            $remaining = (string)\substr($remaining, $written);
        }

        return \strlen($contents);
    }

    /** @param resource $handle */
    protected function flushAll($handle): void
    {
        if (false === $this->flushFile($handle)) {
            throw new Exception(\sprintf('could not flush audit file `%s`', $this->getAuditFile()));
        }
    }

    /** @return resource|false */
    protected function openFile(string $file, string $mode)
    {
        return \fopen($file, $mode);
    }

    /**
     * @param resource $handle
     * @param int<0, 7> $lock
     */
    protected function lockFile($handle, int $lock): bool
    {
        return \flock($handle, $lock);
    }

    /** @param resource $handle */
    protected function writeFile($handle, string $contents): false|int
    {
        return \fwrite($handle, $contents);
    }

    /** @param resource $handle */
    protected function flushFile($handle): bool
    {
        return \fflush($handle);
    }

    protected function getLastErrorSuffix(): string
    {
        $lastError = \error_get_last();

        return null === $lastError ? '' : \sprintf(': %s', $lastError['message']);
    }
}
