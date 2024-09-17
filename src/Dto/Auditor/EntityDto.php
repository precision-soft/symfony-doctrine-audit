<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Auditor;

use PrecisionSoft\Doctrine\Audit\Dto\AbstractEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto as StorageEntityDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;

final class EntityDto extends AbstractEntityDto
{
    public function __construct(string $operation, string $class, string $tableName)
    {
        if (!\in_array($operation, StorageEntityDto::OPERATIONS, true)) {
            throw new Exception(\sprintf('invalid audit operation `%s`', $operation));
        }

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
