<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Auditor;

class Configuration
{
    /** @param string[] $ignoredFields */
    public function __construct(
        protected readonly array $ignoredFields,
    ) {}

    /** @return string[] */
    public function getIgnoredFields(): array
    {
        return $this->ignoredFields;
    }
}
