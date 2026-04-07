<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema;

use Doctrine\ORM\Tools\SchemaTool;

class UpdateCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('update the database schema for the corresponding auditor');
    }

    protected function getSchemaSql(SchemaTool $schemaTool, array $metadatas): array
    {
        return $schemaTool->getUpdateSchemaSql($metadatas);
    }

    protected function executeSchema(SchemaTool $schemaTool, array $metadatas): void
    {
        $schemaTool->updateSchema($metadatas);
    }

    protected function getActionVerb(): string
    {
        return 'updating';
    }

    protected function getCompletedVerb(): string
    {
        return 'updated';
    }
}
