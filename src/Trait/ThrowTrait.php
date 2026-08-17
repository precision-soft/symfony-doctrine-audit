<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Trait;

use PrecisionSoft\Doctrine\Audit\Contract\ExceptionInterface;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use Psr\Log\LoggerInterface;
use Throwable;

trait ThrowTrait
{
    abstract protected function getLogger(): ?LoggerInterface;

    /** @param array<string, mixed> $logContext */
    protected function throw(Throwable $throwable, array $logContext = []): void
    {
        $logger = $this->getLogger();

        if (null !== $logger) {
            $logger->error(
                __CLASS__ . ': ' . $throwable->getMessage(),
                [
                    'code' => $throwable->getCode(),
                    'file' => $throwable->getFile(),
                    'line' => $throwable->getLine(),
                    'trace' => $throwable->getTraceAsString(),
                ] + $logContext,
            );
        }

        /* the rewrap is what a consumer catches, so the context has to travel with it or the facts end up one link down the previous chain, where getContext() will not look */
        $context = true === $throwable instanceof ExceptionInterface ? $throwable->getContext() : [];

        throw new Exception(
            $throwable->getMessage(),
            (int)$throwable->getCode(),
            $throwable,
            [] === $context ? null : $context,
        );
    }
}
