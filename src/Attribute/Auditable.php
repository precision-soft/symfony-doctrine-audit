<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Auditable
{
    public function __construct(
        public readonly bool $enabled = true,
    ) {}
}
