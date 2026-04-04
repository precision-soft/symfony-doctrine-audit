<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto\Storage;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;

/**
 * @internal
 */
final class TransactionDtoTest extends TestCase
{
    public function testGetUsernameWithoutExtras(): void
    {
        $transactionDto = new TransactionDto('admin');

        static::assertSame('admin', $transactionDto->getUsername());
        static::assertSame([], $transactionDto->getExtras());
    }

    public function testGetExtras(): void
    {
        $extras = ['ip' => '127.0.0.1', 'reason' => 'manual correction'];
        $transactionDto = new TransactionDto('admin', $extras);

        static::assertSame('admin', $transactionDto->getUsername());
        static::assertSame($extras, $transactionDto->getExtras());
    }
}
