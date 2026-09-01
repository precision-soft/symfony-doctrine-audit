<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Contract;

use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditPage;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;

/**
 * @experimental The payload shape is not covered by the backward compatibility promise yet: `AuditPage` hands back the
 * jsonl records as they are on disk, and that becomes a dedicated transaction dto once a second storage implements
 * this contract. The method signatures are stable; what they carry is not.
 */
interface AuditReaderInterface
{
    public function read(AuditQuery $query): AuditPage;
}
