<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Storage;

use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use Psr\Log\LoggerInterface;

/**
 * Drives the io failure branches of `FileStorage::appendTransaction()`, which no real filesystem reaches on demand.
 *
 * @internal
 */
class FailingFileStorage extends FileStorage
{
    public function __construct(
        string $file,
        ?LoggerInterface $logger,
        protected readonly bool $lock = true,
        protected readonly bool $write = true,
        protected readonly bool $flush = true,
        protected readonly ?int $partialWriteSize = null,
        protected readonly ?int $failAfterBytes = null,
    ) {
        parent::__construct($file, $logger);
    }

    protected function lockFile($handle, int $lock): bool
    {
        return true === $this->lock ? parent::lockFile($handle, $lock) : false;
    }

    protected function writeFile($handle, string $contents): false|int
    {
        if (false === $this->write) {
            return false;
        }

        /* the io error that leaves bytes behind: what `fwrite` does when the device fills up mid-record */
        if (null !== $this->failAfterBytes) {
            parent::writeFile($handle, (string)\substr($contents, 0, $this->failAfterBytes));

            return false;
        }

        if (null === $this->partialWriteSize) {
            return parent::writeFile($handle, $contents);
        }

        return parent::writeFile($handle, (string)\substr($contents, 0, $this->partialWriteSize));
    }

    protected function flushFile($handle): bool
    {
        return true === $this->flush ? parent::flushFile($handle) : false;
    }
}
