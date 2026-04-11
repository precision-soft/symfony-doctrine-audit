<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage\Doctrine;

class Configuration
{
    private readonly string $transactionTableName;
    private readonly string $transactionIdColumnName;
    private readonly string $transactionIdColumnType;
    private readonly string $operationColumnName;

    /** @param array<string, string> $config */
    public function __construct(array $config)
    {
        $this->transactionTableName = $config['transaction_table_name'] ?? 'audit_transaction';
        $this->transactionIdColumnName = $config['transaction_id_column_name'] ?? 'audit_transaction_id';
        $this->transactionIdColumnType = $config['transaction_id_column_type'] ?? 'integer';
        $this->operationColumnName = $config['operation_column_name'] ?? 'audit_operation';
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
