<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Exception;

use Throwable;

class StorageFailureException extends Exception
{
    /** @var Throwable[] */
    protected readonly array $failures;

    /**
     * @param Throwable[] $failures the exception from every storage that rejected the payload, in call order
     * @param bool $storedPayload whether at least one storage accepted it
     * @param array<string, mixed>|null $context which storages rejected it, which `getFailures()` cannot carry because a `Throwable` does not name the sink that raised it
     */
    public function __construct(
        array $failures,
        protected readonly bool $storedPayload,
        ?array $context = null,
    ) {
        $firstFailure = $failures[0];

        $this->failures = $failures;

        parent::__construct($firstFailure->getMessage(), (int)$firstFailure->getCode(), $firstFailure, $context);
    }

    /** @return Throwable[] */
    public function getFailures(): array
    {
        return $this->failures;
    }

    public function hasStoredPayload(): bool
    {
        return $this->storedPayload;
    }
}
