<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Contract;

use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;

interface StorageInterface
{
    public function save(StorageDto $storageDto): void;
}
