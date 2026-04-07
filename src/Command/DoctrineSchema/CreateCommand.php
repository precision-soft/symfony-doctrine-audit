<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema;

use Doctrine\ORM\Tools\SchemaTool;

class CreateCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('create the database schema for the corresponding auditor');
    }

    protected function getSchemaSql(SchemaTool $schemaTool, array $metadatas): array
    {
        return $schemaTool->getCreateSchemaSql($metadatas);
    }

    protected function executeSchema(SchemaTool $schemaTool, array $metadatas): void
    {
        $schemaTool->createSchema($metadatas);
    }

    protected function getActionVerb(): string
    {
        return 'creating';
    }

    protected function getCompletedVerb(): string
    {
        return 'created';
    }
}
