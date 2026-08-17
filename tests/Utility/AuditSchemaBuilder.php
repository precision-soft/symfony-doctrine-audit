<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfiguration;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\EventSubscriber\DoctrineSchemaListener;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as StorageConfiguration;

/**
 * Must reproduce `Command\DoctrineSchema\AbstractCommand` exactly: `SchemaTool` is `new`'d inside the command, so this is the only way its `execute` path is ever covered.
 *
 * @internal
 */
final class AuditSchemaBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $sourceEntityManager,
        private readonly EntityManagerInterface $destinationEntityManager,
        private readonly AnnotationReadServiceInterface $annotationReadService,
        AuditorConfiguration $auditorConfiguration,
        StorageConfiguration $storageConfiguration,
    ) {
        $listener = new DoctrineSchemaListener(
            $annotationReadService,
            $auditorConfiguration,
            $storageConfiguration,
        );

        $eventManager = $destinationEntityManager->getEventManager();
        $eventManager->addEventListener(
            [ToolEvents::postGenerateSchemaTable],
            new class ($listener) {
                public function __construct(private readonly DoctrineSchemaListener $listener) {}

                public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
                {
                    $this->listener->postGenerateSchemaTable($eventArgs);
                }
            },
        );
        $eventManager->addEventListener(
            [ToolEvents::postGenerateSchema],
            new class ($listener) {
                public function __construct(private readonly DoctrineSchemaListener $listener) {}

                public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
                {
                    $this->listener->postGenerateSchema($eventArgs);
                }
            },
        );
    }

    /** @return list<ClassMetadata<object>> */
    public function getAuditedSourceMetadatas(): array
    {
        return \array_values(\array_filter(
            $this->sourceEntityManager->getMetadataFactory()->getAllMetadata(),
            fn(ClassMetadata $classMetadata) => null !== $this->annotationReadService->buildEntityDto($classMetadata),
        ));
    }

    /** @return string[] */
    public function getCreateSql(): array
    {
        $metadatas = $this->getAuditedSourceMetadatas();

        return $this->createSchemaTool($metadatas)->getCreateSchemaSql($metadatas);
    }

    public function create(): void
    {
        $metadatas = $this->getAuditedSourceMetadatas();

        $this->createSchemaTool($metadatas)->createSchema($metadatas);
    }

    /** @return string[] */
    public function getUpdateSql(): array
    {
        $metadatas = $this->getAuditedSourceMetadatas();

        return $this->createSchemaTool($metadatas)->getUpdateSchemaSql($metadatas);
    }

    /** @param list<ClassMetadata<object>> $sourceMetadatas */
    private function createSchemaTool(array $sourceMetadatas): SchemaTool
    {
        foreach ($sourceMetadatas as $classMetadata) {
            $this->destinationEntityManager->getMetadataFactory()
                ->setMetadataFor($classMetadata->getName(), $classMetadata);
        }

        return new SchemaTool($this->destinationEntityManager);
    }
}
