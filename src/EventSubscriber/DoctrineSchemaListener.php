<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\EventSubscriber;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfiguration;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as StorageConfiguration;
use PrecisionSoft\Doctrine\Type\Contract\AbstractEnumType;
use PrecisionSoft\Doctrine\Type\Contract\AbstractSetType;
use Throwable;

class DoctrineSchemaListener
{
    public function __construct(
        protected readonly AnnotationReadServiceInterface $annotationReadService,
        protected readonly AuditorConfiguration $auditorConfiguration,
        protected readonly StorageConfiguration $storageConfiguration,
    ) {}

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $entityTable = $eventArgs->getClassTable();
        $schema = $eventArgs->getSchema();

        try {
            $tableIsAuditable = false;

            try {
                $tableIsAuditable = $this->configureAuditTable($eventArgs, $schema, $entityTable);
            } finally {
                if (false === $tableIsAuditable) {
                    $schema->dropTable($entityTable->getName());
                }
            }
        } catch (Throwable $throwable) {
            throw new Exception(
                \sprintf('`%s` => `%s`', $entityTable->getName(), $throwable->getMessage()),
                (int)$throwable->getCode(),
                $throwable,
                ['entityTableName' => $entityTable->getName()],
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
            $transactionTable->addColumn('extras', Types::TEXT)->setNotnull(false);
            $transactionTable->addColumn(
                $this->storageConfiguration->getCollectionChangesColumnName(),
                Types::JSON,
            )->setNotnull(false);

            $transactionTable->setPrimaryKey(['id']);

            foreach ($schema->getTables() as $table) {
                if ($this->storageConfiguration->getTransactionTableName() === $table->getName()) {
                    continue;
                }

                /* Anything without the transaction id column is not an audit table - a many-to-many join table, say - and constraining it would throw on the missing local column and take the whole audit schema down. */
                if (false === $table->hasColumn($this->storageConfiguration->getTransactionIdColumnName())) {
                    $schema->dropTable($table->getName());

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
                ['transactionTableName' => $this->storageConfiguration->getTransactionTableName()],
            );
        }
    }

    protected function configureAuditTable(GenerateSchemaTableEventArgs $eventArgs, Schema $schema, Table $entityTable): bool
    {
        $classMetadata = $eventArgs->getClassMetadata();

        $entityDto = $this->annotationReadService->buildEntityDto($classMetadata);

        if (null === $entityDto) {
            return false;
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
                    if (false === $associationMapping instanceof ManyToOneAssociationMapping
                        && false === $associationMapping instanceof OneToOneOwningSideMapping) {
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

        if ([] === $table->getColumns()) {
            return false;
        }

        $table->addColumn(
            $this->storageConfiguration->getTransactionIdColumnName(),
            $this->storageConfiguration->getTransactionIdColumnType(),
        );
        /* Types::ENUM and not AuditOperationType, for identical DDL: DBAL 4 introspects a MySQL enum back into its own EnumType and never a subclass, so the custom type never round-trips and schema:update re-issues the same ALTER forever */
        $table->addColumn(
            $this->storageConfiguration->getOperationColumnName(),
            Types::ENUM,
            ['notnull' => true, 'values' => Operation::values()],
        );

        $primaryKey = $entityTable->getPrimaryKey();

        if (null === $primaryKey) {
            throw new Exception(
                \sprintf('entity table `%s` has no primary key — audited entities must have one', $entityTable->getName()),
            );
        }
        $primaryKeyColumns = $primaryKey->getColumns();
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

        foreach ($primaryKeyColumns as $primaryKeyColumn) {
            if (false === $table->hasColumn($primaryKeyColumn)) {
                throw new Exception(
                    \sprintf(
                        'primary key column `%s` was dropped — identifier fields cannot be added to the ignored fields list',
                        $primaryKeyColumn,
                    ),
                );
            }
        }

        $table->setPrimaryKey($primaryKeyColumns);

        $table->addIndex(
            [$this->storageConfiguration->getTransactionIdColumnName()],
            $this->storageConfiguration->getTransactionIdColumnName(),
        );

        return true;
    }

    protected function updateType(Column $column): void
    {
        $columnType = $column->getType();

        if (true === $columnType instanceof AbstractEnumType || true === $columnType instanceof AbstractSetType) {
            $column->setType(Type::getType(Types::STRING))->setLength(255);
        }
    }
}
