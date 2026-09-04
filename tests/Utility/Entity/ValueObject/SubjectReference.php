<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\ValueObject;

use Stringable;

/** Stands in for `Symfony\Component\Uid\Uuid` and doctrine-utility's uid types: an identifier that is an object and knows its own string form. */
class SubjectReference implements Stringable
{
    public function __construct(protected readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
