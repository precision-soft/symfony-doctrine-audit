<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage\Doctrine;

use Doctrine\DBAL\Types\Types;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;

class Configuration
{
    protected const DEFAULT_TRANSACTION_TABLE = 'audit_transaction';
    protected const DEFAULT_TRANSACTION_ID_COLUMN = 'audit_transaction_id';
    protected const DEFAULT_TRANSACTION_ID_TYPE = Types::INTEGER;
    protected const DEFAULT_OPERATION_COLUMN = 'audit_operation';
    protected const DEFAULT_COLLECTION_CHANGES_COLUMN = 'collection_changes';

    /**
     * Only an integer identity type can work: the id is autoincrement and `Storage::getTransactionId()` reads it back through `lastInsertId()`, while `guid` yields a `char(36)` whose autoincrement is quietly dropped, so `CREATE TABLE` succeeds and every audited flush fails afterwards.
     *
     * @var string[]
     */
    protected const SUPPORTED_TRANSACTION_ID_TYPES = [Types::INTEGER, Types::BIGINT, Types::SMALLINT];

    protected readonly string $transactionTableName;
    protected readonly string $transactionIdColumnName;
    protected readonly string $transactionIdColumnType;
    protected readonly string $operationColumnName;
    protected readonly string $collectionChangesColumnName;

    /** @param array<string, string> $config */
    public function __construct(array $config)
    {
        $this->transactionTableName = $config['transaction_table_name'] ?? static::DEFAULT_TRANSACTION_TABLE;
        $this->transactionIdColumnName = $config['transaction_id_column_name'] ?? static::DEFAULT_TRANSACTION_ID_COLUMN;
        $transactionIdColumnType = $config['transaction_id_column_type'] ?? static::DEFAULT_TRANSACTION_ID_TYPE;

        if (false === \in_array($transactionIdColumnType, static::SUPPORTED_TRANSACTION_ID_TYPES, true)) {
            throw new Exception(
                \sprintf(
                    'invalid `transaction_id_column_type` `%s`; the audit transaction id is an autoincrement column, so it must be one of `%s`',
                    $transactionIdColumnType,
                    \implode('`, `', static::SUPPORTED_TRANSACTION_ID_TYPES),
                ),
            );
        }

        $this->transactionIdColumnType = $transactionIdColumnType;
        $this->operationColumnName = $config['operation_column_name'] ?? static::DEFAULT_OPERATION_COLUMN;
        $this->collectionChangesColumnName = $config['collection_changes_column_name'] ?? static::DEFAULT_COLLECTION_CHANGES_COLUMN;
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

    public function getCollectionChangesColumnName(): string
    {
        return $this->collectionChangesColumnName;
    }
}
