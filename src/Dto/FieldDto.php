<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto;

class FieldDto
{
    public function __construct(
        protected readonly string $name,
        protected readonly string $columnName,
        protected readonly string $type,
        protected readonly mixed  $value,
        protected readonly mixed  $oldValue = null,
        protected readonly bool   $hasOldValue = false,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getColumnName(): string
    {
        return $this->columnName;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getOldValue(): mixed
    {
        return $this->oldValue;
    }

    public function hasOldValue(): bool
    {
        return $this->hasOldValue;
    }
}
