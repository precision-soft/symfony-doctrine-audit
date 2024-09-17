<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Contract;

use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;

interface TransactionProviderInterface
{
    public function getTransaction(): TransactionDto;
}
