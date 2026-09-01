<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Query;

/** @experimental `getTransactions()` returns the jsonl records verbatim; see `AuditReaderInterface`. */
class AuditPage
{
    /** @param list<array<string, mixed>> $transactions */
    public function __construct(
        protected readonly array $transactions,
        protected readonly ?string $nextCursor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function getTransactions(): array
    {
        return $this->transactions;
    }

    public function getNextCursor(): ?string
    {
        return $this->nextCursor;
    }
}
