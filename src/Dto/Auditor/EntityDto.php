<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Auditor;

use PrecisionSoft\Doctrine\Audit\Dto\AbstractEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

final class EntityDto extends AbstractEntityDto
{
    public function __construct(Operation $operation, string $class, string $tableName)
    {
        $this->operation = $operation;
        $this->class = $class;
        $this->tableName = $tableName;
        $this->fields = [];
    }

    public function addField(FieldDto $fieldDto): self
    {
        $this->fields[] = $fieldDto;

        return $this;
    }
}
