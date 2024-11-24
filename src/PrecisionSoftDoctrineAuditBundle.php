<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit;

use Doctrine\DBAL\Types\Type;
use PrecisionSoft\Doctrine\Audit\Type\AuditOperationType;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PrecisionSoftDoctrineAuditBundle extends Bundle
{
    public function boot(): void
    {
        parent::boot();

        if (false === Type::hasType(AuditOperationType::getDefaultName())) {
            Type::addType(AuditOperationType::getDefaultName(), AuditOperationType::class);
        }
    }
}
