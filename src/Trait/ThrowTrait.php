<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Trait;

use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use Psr\Log\LoggerInterface;
use Throwable;

trait ThrowTrait
{
    abstract private function getLogger(): ?LoggerInterface;

    private function throw(Throwable $throwable, array $logContext = []): void
    {
        $logger = $this->getLogger();

        if (null !== $logger) {
            $logger->error(
                __CLASS__ . ': ' . $throwable->getMessage(),
                $logContext + [
                    'code' => $throwable->getCode(),
                    'file' => $throwable->getFile(),
                    'line' => $throwable->getLine(),
                    'trace' => $throwable->getTraceAsString(),
                ],
            );
        }

        throw new Exception($throwable->getMessage(), (int)$throwable->getCode(), $throwable);
    }
}
