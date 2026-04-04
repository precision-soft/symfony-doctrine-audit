<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Symfony\Console\Command\AbstractCommand as ConsoleAbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class AbstractCommand extends ConsoleAbstractCommand
{
    protected const FORCE = 'force';

    public function __construct(
        string $name,
        protected readonly EntityManagerInterface $sourceEntityManager,
        protected readonly EntityManagerInterface $destinationEntityManager,
        protected readonly AnnotationReadService $annotationReadService,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(static::FORCE, null, InputOption::VALUE_NONE, 'run the sql');
    }

    protected function getAuditedSourceMetadatas(): array
    {
        return \array_values(\array_filter(
            $this->sourceEntityManager->getMetadataFactory()->getAllMetadata(),
            fn($classMetadata) => null !== $this->annotationReadService->buildEntityDto($classMetadata),
        ));
    }

    protected function createSchemaTool(): SchemaTool
    {
        $sourceMetadatas = $this->getAuditedSourceMetadatas();

        foreach ($sourceMetadatas as $classMetadata) {
            $this->destinationEntityManager->getMetadataFactory()
                ->setMetadataFor($classMetadata->getName(), $classMetadata);
        }

        return new SchemaTool($this->destinationEntityManager);
    }

    /** Return the SQL statements to preview. */
    abstract protected function getSchemaSql(SchemaTool $schemaTool, array $metadatas): array;

    /** Execute the schema operation. */
    abstract protected function executeSchema(SchemaTool $schemaTool, array $metadatas): void;

    /** Return the verb used in progress messages, e.g. "creating" or "updating". */
    abstract protected function getActionVerb(): string;

    /** Return the past-tense verb for success messages, e.g. "created" or "updated". */
    abstract protected function getCompletedVerb(): string;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->warning('careful when running this in a production environment');

            $sourceMetadatas = $this->getAuditedSourceMetadatas();

            $schemaTool = $this->createSchemaTool();

            $this->writeln('the following sql statements will be executed');

            $sqls = $this->getSchemaSql($schemaTool, $sourceMetadatas);

            foreach ($sqls as $sql) {
                $this->style->writeln(\sprintf('    %s;', $sql));
            }

            $this->writeln('----------------------------------------------------------------------');

            $force = true === $input->getOption(self::FORCE);
            if (true === $force) {
                $this->writeln(\sprintf('%s database schema', $this->getActionVerb()));

                $this->executeSchema($schemaTool, $sourceMetadatas);

                $this->success(\sprintf('database schema %s successfully', $this->getCompletedVerb()));
            }
        } catch (Throwable $t) {
            $this->error($t->getMessage(), $t, true);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
