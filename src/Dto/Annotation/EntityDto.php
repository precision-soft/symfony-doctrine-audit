<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Annotation;

class EntityDto
{
    /** @param string[] $ignoredFields */
    public function __construct(
        protected readonly string $class,
        protected readonly array $ignoredFields,
    ) {}

    public function getClass(): string
    {
        return $this->class;
    }

    /** @return string[] */
    public function getIgnoredFields(): array
    {
        return $this->ignoredFields;
    }
}
