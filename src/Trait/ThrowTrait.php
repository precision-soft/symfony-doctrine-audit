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

    private function throw(Throwable $t, array $logContext = []): void
    {
        $logger = $this->getLogger();

        if (null !== $logger) {
            $logger->error(
                __CLASS__ . ': ' . $t->getMessage(),
                $logContext + [
                    'code' => $t->getCode(),
                    'file' => $t->getFile(),
                    'line' => $t->getLine(),
                    'trace' => $t->getTrace(),
                ],
            );
        }

        throw new Exception($t->getMessage(), $t->getCode(), $t);
    }
}
