<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Annotation;

final class EntityDto
{
    public function __construct(
        private readonly string $class,
        private readonly array $ignoredFields,
    ) {}

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function getIgnoredFields(): ?array
    {
        return $this->ignoredFields;
    }
}
