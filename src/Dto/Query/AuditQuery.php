<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Query;

use DateTimeImmutable;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;

class AuditQuery
{
    public const DEFAULT_LIMIT = 100;

    protected const MINIMUM_LIMIT = 1;
    protected const MAXIMUM_LIMIT = 1000;

    /** @param array<string, scalar|null> $identity */
    public function __construct(
        protected readonly ?string $entityClass = null,
        protected readonly array $identity = [],
        protected readonly ?DateTimeImmutable $from = null,
        protected readonly ?DateTimeImmutable $until = null,
        protected readonly ?string $username = null,
        protected readonly ?Operation $operation = null,
        protected readonly int $limit = self::DEFAULT_LIMIT,
        protected readonly ?string $cursor = null,
    ) {
        if (static::MINIMUM_LIMIT > $limit || static::MAXIMUM_LIMIT < $limit) {
            throw new Exception(
                \sprintf(
                    'audit query limit must be between %d and %d, got %d',
                    static::MINIMUM_LIMIT,
                    static::MAXIMUM_LIMIT,
                    $limit,
                ),
            );
        }
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    /** @return array<string, scalar|null> */
    public function getIdentity(): array
    {
        return $this->identity;
    }

    public function getFrom(): ?DateTimeImmutable
    {
        return $this->from;
    }

    public function getUntil(): ?DateTimeImmutable
    {
        return $this->until;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getOperation(): ?Operation
    {
        return $this->operation;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }
}
