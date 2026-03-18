<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use Symfony\Component\Console\Input\InputOption;

abstract class AbstractCommand extends \PrecisionSoft\Symfony\Console\Command\AbstractCommand
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
            fn($metadata) => null !== $this->annotationReadService->buildEntityDto($metadata),
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
}
