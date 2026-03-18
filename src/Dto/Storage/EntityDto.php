<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto\Storage;

use PrecisionSoft\Doctrine\Audit\Dto\AbstractEntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

final class EntityDto extends AbstractEntityDto
{
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
