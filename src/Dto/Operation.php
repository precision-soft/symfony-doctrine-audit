<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Dto;

enum Operation: string
{
    case Delete = 'delete';
    case Insert = 'insert';
    case Update = 'update';

    /** @return string[] */
    public static function values(): array
    {
        return \array_column(self::cases(), 'value');
    }
}
