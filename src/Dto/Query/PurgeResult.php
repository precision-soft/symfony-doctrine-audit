<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Query;

class PurgeResult
{
    public function __construct(
        protected readonly int $matchedTransactions,
        protected readonly int $purgedTransactions,
        protected readonly bool $hasMore,
    ) {}

    public function getMatchedTransactions(): int
    {
        return $this->matchedTransactions;
    }

    public function getPurgedTransactions(): int
    {
        return $this->purgedTransactions;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }
}
