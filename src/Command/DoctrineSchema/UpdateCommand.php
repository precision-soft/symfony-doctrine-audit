<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class UpdateCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->setDescription('update the database schema for the corresponding auditor');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->warning('careful when running this in a production environment');

            $sourceMetadatas = $this->getAuditedSourceMetadatas();

            $schemaTool = $this->createSchemaTool();

            $this->writeln('the following sql statements will be executed');

            $sqls = $schemaTool->getUpdateSchemaSql($sourceMetadatas, true);

            foreach ($sqls as $sql) {
                $this->style->writeln(\sprintf('    %s;', $sql));
            }

            $this->writeln('----------------------------------------------------------------------');

            $force = true === $input->getOption(self::FORCE);
            if ($force) {
                $this->writeln('updating database schema');

                $schemaTool->updateSchema($sourceMetadatas, true);

                $this->success('database schema updated successfully');
            }
        } catch (Throwable $t) {
            $this->error($t->getMessage(), $t, true);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
