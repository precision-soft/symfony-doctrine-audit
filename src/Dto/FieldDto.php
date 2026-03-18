<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto;

final class FieldDto
{
    public function __construct(
        private readonly string $name,
        private readonly string $columnName,
        private readonly string $type,
        private readonly mixed $value,
        private readonly mixed $oldValue = null,
    ) {}

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getColumnName(): ?string
    {
        return $this->columnName;
    }

    public function getType(): ?string
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
}
