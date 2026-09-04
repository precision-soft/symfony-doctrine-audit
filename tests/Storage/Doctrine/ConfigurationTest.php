<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Storage\Doctrine;

use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration;

/**
 * @internal
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $configuration = new Configuration([]);

        static::assertSame('audit_transaction', $configuration->getTransactionTableName());
        static::assertSame('audit_transaction_id', $configuration->getTransactionIdColumnName());
        static::assertSame('integer', $configuration->getTransactionIdColumnType());
        static::assertSame('audit_operation', $configuration->getOperationColumnName());
        static::assertSame('collection_changes', $configuration->getCollectionChangesColumnName());
    }

    public function testCustomValues(): void
    {
        $configuration = new Configuration([
            'transaction_table_name' => 'custom_transaction',
            'transaction_id_column_name' => 'custom_id',
            'transaction_id_column_type' => 'bigint',
            'operation_column_name' => 'custom_op',
            'collection_changes_column_name' => 'custom_collections',
        ]);

        static::assertSame('custom_transaction', $configuration->getTransactionTableName());
        static::assertSame('custom_id', $configuration->getTransactionIdColumnName());
        static::assertSame('bigint', $configuration->getTransactionIdColumnType());
        static::assertSame('custom_op', $configuration->getOperationColumnName());
        static::assertSame('custom_collections', $configuration->getCollectionChangesColumnName());
    }

    public function testPartialOverride(): void
    {
        $configuration = new Configuration([
            'transaction_table_name' => 'my_txn',
        ]);

        static::assertSame('my_txn', $configuration->getTransactionTableName());
        static::assertSame('audit_transaction_id', $configuration->getTransactionIdColumnName());
        static::assertSame('integer', $configuration->getTransactionIdColumnType());
        static::assertSame('audit_operation', $configuration->getOperationColumnName());
        static::assertSame('collection_changes', $configuration->getCollectionChangesColumnName());
    }

    public function testANonIntegerTransactionIdColumnTypeIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/transaction_id_column_type/');

        new Configuration(['transaction_id_column_type' => Types::GUID]);
    }

    public function testTheIntegerFamilyIsAccepted(): void
    {
        foreach ([Types::INTEGER, Types::BIGINT, Types::SMALLINT] as $columnType) {
            $configuration = new Configuration(['transaction_id_column_type' => $columnType]);

            static::assertSame($columnType, $configuration->getTransactionIdColumnType());
        }
    }
}
