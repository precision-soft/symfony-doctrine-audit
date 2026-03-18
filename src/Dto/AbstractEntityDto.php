<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto;

abstract class AbstractEntityDto
{
    protected Operation $operation;
    protected string $class;
    protected string $tableName;
    protected array $fields;

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    /** @return FieldDto[] */
    public function getFields(): array
    {
        return $this->fields;
    }
}
