<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

use PrecisionSoft\Doctrine\Audit\Dto\AbstractEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\FieldDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

class EntityDto extends AbstractEntityDto
{
    /** @param FieldDto[] $fields */
    public function __construct(
        Operation $operation,
        string $class,
        string $tableName,
        array $fields,
    ) {
        $this->operation = $operation;
        $this->class = $class;
        $this->tableName = $tableName;
        $this->fields = $fields;
    }
}
