<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Annotation;

final class EntityDto
{
    /** @param string[] $ignoredFields */
    public function __construct(
        private readonly string $class,
        private readonly array $ignoredFields,
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
