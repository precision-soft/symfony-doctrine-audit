<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage\Doctrine;

class Configuration
{
    private const DEFAULT_TRANSACTION_TABLE = 'audit_transaction';
    private const DEFAULT_TRANSACTION_ID_COLUMN = 'audit_transaction_id';
    private const DEFAULT_TRANSACTION_ID_TYPE = 'integer';
    private const DEFAULT_OPERATION_COLUMN = 'audit_operation';

    private readonly string $transactionTableName;
    private readonly string $transactionIdColumnName;
    private readonly string $transactionIdColumnType;
    private readonly string $operationColumnName;

    /** @param array<string, string> $config */
    public function __construct(array $config)
    {
        $this->transactionTableName = $config['transaction_table_name'] ?? self::DEFAULT_TRANSACTION_TABLE;
        $this->transactionIdColumnName = $config['transaction_id_column_name'] ?? self::DEFAULT_TRANSACTION_ID_COLUMN;
        $this->transactionIdColumnType = $config['transaction_id_column_type'] ?? self::DEFAULT_TRANSACTION_ID_TYPE;
        $this->operationColumnName = $config['operation_column_name'] ?? self::DEFAULT_OPERATION_COLUMN;
    }

    public function getTransactionTableName(): string
    {
        return $this->transactionTableName;
    }

    public function getTransactionIdColumnName(): string
    {
        return $this->transactionIdColumnName;
    }

    public function getTransactionIdColumnType(): string
    {
        return $this->transactionIdColumnType;
    }

    public function getOperationColumnName(): string
    {
        return $this->operationColumnName;
    }
}
