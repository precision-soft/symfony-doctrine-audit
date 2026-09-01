<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Query;

use DateTimeImmutable;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;

class PurgeRequest
{
    public const DEFAULT_BATCH_SIZE = 500;

    protected const MINIMUM_BATCH_SIZE = 1;
    protected const MAXIMUM_BATCH_SIZE = 10000;

    public function __construct(
        protected readonly DateTimeImmutable $before,
        protected readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        protected readonly bool $dryRun = true,
    ) {
        if (static::MINIMUM_BATCH_SIZE > $batchSize || static::MAXIMUM_BATCH_SIZE < $batchSize) {
            throw new Exception(
                \sprintf(
                    'purge batch size must be between %d and %d, got %d',
                    static::MINIMUM_BATCH_SIZE,
                    static::MAXIMUM_BATCH_SIZE,
                    $batchSize,
                ),
            );
        }
    }

    public function getBefore(): DateTimeImmutable
    {
        return $this->before;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function getDryRun(): bool
    {
        return $this->dryRun;
    }
}
