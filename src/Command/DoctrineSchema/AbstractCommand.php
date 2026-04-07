<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
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
        protected readonly AnnotationReadServiceInterface $annotationReadService,
    ) {
        parent::__construct($name);
    }

    abstract protected function getSchemaSql(SchemaTool $schemaTool, array $metadatas): array;

    abstract protected function executeSchema(SchemaTool $schemaTool, array $metadatas): void;

    /** @info e.g. "creating" or "updating" */
    abstract protected function getActionVerb(): string;

    /** @info e.g. "created" or "updated" */
    abstract protected function getCompletedVerb(): string;

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

    protected function createSchemaTool(array $sourceMetadatas): SchemaTool
    {
        foreach ($sourceMetadatas as $classMetadata) {
            $this->destinationEntityManager->getMetadataFactory()
                ->setMetadataFor($classMetadata->getName(), $classMetadata);
        }

        return new SchemaTool($this->destinationEntityManager);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->warning('careful when running this in a production environment');

            $sourceMetadatas = $this->getAuditedSourceMetadatas();

            $schemaTool = $this->createSchemaTool($sourceMetadatas);

            $this->writeln('the following sql statements will be executed');

            $sqlStatements = $this->getSchemaSql($schemaTool, $sourceMetadatas);

            foreach ($sqlStatements as $sqlStatement) {
                $this->style->writeln(\sprintf('    %s;', $sqlStatement));
            }

            $this->writeln('----------------------------------------------------------------------');

            if (true === $input->getOption(self::FORCE)) {
                $this->writeln(\sprintf('%s database schema', $this->getActionVerb()));

                $this->executeSchema($schemaTool, $sourceMetadatas);

                $this->success(\sprintf('database schema %s successfully', $this->getCompletedVerb()));
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage(), $throwable, true);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
