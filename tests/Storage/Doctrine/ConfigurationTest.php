<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Storage\Doctrine;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration;

/**
 * @internal
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new Configuration([]);

        static::assertSame('audit_transaction', $config->getTransactionTableName());
        static::assertSame('audit_transaction_id', $config->getTransactionIdColumnName());
        static::assertSame('integer', $config->getTransactionIdColumnType());
        static::assertSame('audit_operation', $config->getOperationColumnName());
    }

    public function testCustomValues(): void
    {
        $config = new Configuration([
            'transaction_table_name' => 'custom_transaction',
            'transaction_id_column_name' => 'custom_id',
            'transaction_id_column_type' => 'bigint',
            'operation_column_name' => 'custom_op',
        ]);

        static::assertSame('custom_transaction', $config->getTransactionTableName());
        static::assertSame('custom_id', $config->getTransactionIdColumnName());
        static::assertSame('bigint', $config->getTransactionIdColumnType());
        static::assertSame('custom_op', $config->getOperationColumnName());
    }

    public function testPartialOverride(): void
    {
        $config = new Configuration([
            'transaction_table_name' => 'my_txn',
        ]);

        static::assertSame('my_txn', $config->getTransactionTableName());
        static::assertSame('audit_transaction_id', $config->getTransactionIdColumnName());
        static::assertSame('integer', $config->getTransactionIdColumnType());
        static::assertSame('audit_operation', $config->getOperationColumnName());
    }
}
