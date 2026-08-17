<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A named host rather than an anonymous class, which is typed `object` at every call site and leaves the trait's signature unchecked.
 *
 * @internal
 */
final class ThrowTraitUser
{
    use ThrowTrait;

    public function __construct(private readonly ?LoggerInterface $logger) {}

    /** @param array<string, mixed> $logContext */
    public function doThrow(Throwable $throwable, array $logContext = []): void
    {
        $this->throw($throwable, $logContext);
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
