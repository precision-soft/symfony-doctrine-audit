<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Type;

use PrecisionSoft\Doctrine\Audit\Dto\Auditor\EntityDto;
use PrecisionSoft\Doctrine\Type\Contract\AbstractEnumType;

class AuditOperationType extends AbstractEnumType
{
    public function getValues(): array
    {
        return EntityDto::OPERATIONS;
    }
}
