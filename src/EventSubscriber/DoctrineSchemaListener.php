<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\EventSubscriber;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfiguration;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as StorageConfiguration;
use PrecisionSoft\Doctrine\Audit\Type\AuditOperationType;
use PrecisionSoft\Doctrine\Type\Contract\AbstractEnumType;
use PrecisionSoft\Doctrine\Type\Contract\AbstractSetType;
use Throwable;

class DoctrineSchemaListener
{
    public function __construct(
        private readonly AnnotationReadServiceInterface $annotationReadService,
        private readonly AuditorConfiguration $auditorConfiguration,
        private readonly StorageConfiguration $storageConfiguration,
    ) {}

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $classMetadata = $eventArgs->getClassMetadata();
        $schema = $eventArgs->getSchema();
        $entityTable = $eventArgs->getClassTable();

        try {
            $auditable = false;

            try {
                $entityDto = $this->annotationReadService->buildEntityDto($classMetadata);
                if (null === $entityDto) {
                    return;
                }

                $table = $schema->getTable($entityTable->getName());

                foreach ($table->getColumns() as $column) {
                    $columnName = $column->getName();

                    $field = null;
                    foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
                        $mappedColumnName = $mapping->columnName;
                        if ($columnName === $mappedColumnName) {
                            $field = $fieldName;
                            break;
                        }
                    }
                    if (null === $field) {
                        foreach ($classMetadata->associationMappings as $associationFieldName => $associationMapping) {
                            if (false === isset($associationMapping->joinColumns)) {
                                continue;
                            }

                            foreach ($associationMapping->joinColumns as $joinColumn) {
                                if ($columnName === $joinColumn->name) {
                                    $field = $associationFieldName;
                                    break 2;
                                }
                            }
                        }
                    }

                    if (null === $field) {
                        continue;
                    }

                    if (true === \in_array($field, $entityDto->getIgnoredFields(), true)
                        || true === \in_array($field, $this->auditorConfiguration->getIgnoredFields(), true)
                    ) {
                        $table->dropColumn($columnName);
                        continue;
                    }

                    $column->setAutoincrement(false)
                        ->setNotnull(false);

                    $this->updateType($column);
                }

                if (true === empty($table->getColumns())) {
                    return;
                }

                $auditable = true;

                $table->addColumn(
                    $this->storageConfiguration->getTransactionIdColumnName(),
                    $this->storageConfiguration->getTransactionIdColumnType(),
                );
                $table->addColumn(
                    $this->storageConfiguration->getOperationColumnName(),
                    AuditOperationType::getDefaultName(),
                    ['notnull' => true],
                );

                $primaryKeyColumns = $entityTable->getPrimaryKey()->getColumns();
                $primaryKeyColumns[] = $this->storageConfiguration->getTransactionIdColumnName();

                foreach ($table->getForeignKeys() as $foreignKey) {
                    $table->removeForeignKey($foreignKey->getName());
                }

                $table->dropPrimaryKey();
                foreach ($table->getIndexes() as $index) {
                    $table->dropIndex($index->getName());
                }

                foreach ($table->getUniqueConstraints() as $uniqueConstraint) {
                    $table->removeUniqueConstraint($uniqueConstraint->getName());
                }

                $table->setPrimaryKey($primaryKeyColumns);

                $table->addIndex(
                    [$this->storageConfiguration->getTransactionIdColumnName()],
                    $this->storageConfiguration->getTransactionIdColumnName(),
                );
            } finally {
                if (false === $auditable) {
                    $schema->dropTable($entityTable->getName());
                }
            }
        } catch (Throwable $throwable) {
            throw new Exception(
                \sprintf('`%s` => `%s`', $entityTable->getName(), $throwable->getMessage()),
                (int)$throwable->getCode(),
                $throwable,
            );
        }
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
    {
        try {
            $schema = $eventArgs->getSchema();

            $transactionTable = $schema->createTable(
                $this->storageConfiguration->getTransactionTableName(),
            );

            $transactionTable->addColumn(
                'id',
                $this->storageConfiguration->getTransactionIdColumnType(),
                [
                    'autoincrement' => true,
                    'notnull' => true,
                ],
            );
            $transactionTable->addColumn('username', Types::STRING, ['length' => 500])->setNotnull(false);
            $transactionTable->addColumn('created', Types::DATETIME_IMMUTABLE);

            $transactionTable->setPrimaryKey(['id']);

            foreach ($schema->getTables() as $table) {
                if ($this->storageConfiguration->getTransactionTableName() === $table->getName()) {
                    continue;
                }

                $table->addForeignKeyConstraint(
                    $this->storageConfiguration->getTransactionTableName(),
                    [$this->storageConfiguration->getTransactionIdColumnName()],
                    ['id'],
                    ['onDelete' => 'RESTRICT'],
                );
            }
        } catch (Throwable $throwable) {
            throw new Exception(
                \sprintf('`%s` => `%s`', $this->storageConfiguration->getTransactionTableName(), $throwable->getMessage()),
                (int)$throwable->getCode(),
                $throwable,
            );
        }
    }

    private function updateType(Column $column): void
    {
        $columnType = $column->getType();

        if (true === $columnType instanceof AbstractEnumType || true === $columnType instanceof AbstractSetType) {
            $column->setType(Type::getType(Types::STRING))->setLength(255);
        }
    }
}
