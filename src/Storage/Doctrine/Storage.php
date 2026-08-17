<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Throwable;

class Storage implements StorageInterface
{
    use ThrowTrait;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly Configuration $configuration,
        protected readonly ?LoggerInterface $logger,
    ) {}

    public function save(StorageDto $storageDto): void
    {
        if ([] === $storageDto->getEntities()) {
            return;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $transactionId = $this->getTransactionId($storageDto->getTransaction());

            foreach ($storageDto->getEntities() as $entity) {
                $this->saveEntity($transactionId, $entity);
            }

            $connection->commit();
        } catch (Throwable $throwable) {
            try {
                $connection->rollBack();
            } catch (Throwable $rollbackThrowable) {
                $this->getLogger()?->error('rollback failed', ['exception' => $rollbackThrowable]);
            }

            if (true === $throwable instanceof Exception) {
                throw $throwable;
            }

            $this->throw($throwable);
        }
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    protected function getTransactionId(TransactionDto $transactionDto): int
    {
        $connection = $this->entityManager->getConnection();

        $data = [
            'username' => $transactionDto->getUsername(),
            'created' => new DateTimeImmutable(),
        ];
        $types = [
            'username' => Types::STRING,
            'created' => Types::DATETIME_IMMUTABLE,
        ];

        if ([] !== $transactionDto->getExtras()) {
            $data['extras'] = \json_encode($transactionDto->getExtras(), \JSON_THROW_ON_ERROR);
            $types['extras'] = Types::TEXT;
        }

        $connection->insert(
            $this->configuration->getTransactionTableName(),
            $data,
            $types,
        );

        /* the cast is deliberate: a non-numeric lastInsertId() becomes 0 and falls into the guard below */
        $lastInsertId = (int)$connection->lastInsertId();
        if (0 >= $lastInsertId) {
            throw new Exception('failed to retrieve last insert id');
        }

        return $lastInsertId;
    }

    protected function saveEntity(int $transactionId, EntityDto $entityDto): void
    {
        $columns = [
            $this->configuration->getTransactionIdColumnName(),
            $this->configuration->getOperationColumnName(),
        ];
        $values = [$transactionId, $entityDto->getOperation()->value];
        $types = [$this->configuration->getTransactionIdColumnType(), Types::STRING];

        foreach ($entityDto->getFields() as $fieldDto) {
            $columns[] = $fieldDto->getColumnName();
            $values[] = $fieldDto->getValue();
            $types[] = $fieldDto->getType();
        }

        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        $quotedTable = $platform->quoteIdentifier($entityDto->getTableName());
        $quotedColumns = \array_map(fn(string $columnName) => $platform->quoteIdentifier($columnName), $columns);

        $sqlStatement = \sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $quotedTable,
            \implode(', ', $quotedColumns),
            \implode(', ', \array_fill(0, \count($columns), '?')),
        );

        try {
            $connection->executeStatement($sqlStatement, $values, $types);
        } catch (Throwable $throwable) {
            $this->throw($throwable, ['sql' => $sqlStatement]);
        }
    }
}
