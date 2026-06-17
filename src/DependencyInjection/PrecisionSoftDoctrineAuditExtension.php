<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\DependencyInjection;

use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\ToolEvents;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Auditor\Configuration as AuditorConfig;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\CreateCommand;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\UpdateCommand;
use PrecisionSoft\Doctrine\Audit\EventSubscriber\DoctrineSchemaListener;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration as DoctrineConfig;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Storage;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class PrecisionSoftDoctrineAuditExtension extends Extension
{
    protected const BASE_COMMAND_NAME = 'precision-soft:doctrine:audit';
    protected const BASE_SERVICE_ID = 'precision_soft_doctrine_audit';

    public function load(array $configs, ContainerBuilder $containerBuilder): void
    {
        $phpFileLoader = new PhpFileLoader($containerBuilder, new FileLocator(__DIR__ . '/../Resources/config'));
        $phpFileLoader->load('services.php');

        $configuration = new Configuration();
        $processedConfig = $this->processConfiguration($configuration, $configs);

        $this->defineStorages($containerBuilder, $processedConfig['storages']);

        $this->defineAuditors($containerBuilder, $processedConfig['auditors']);

        $this->defineServices($containerBuilder, $processedConfig['auditors'], $processedConfig['storages']);
    }

    /** @param array<string, mixed> $storages */
    protected function defineStorages(ContainerBuilder $containerBuilder, array $storages): void
    {
        foreach ($storages as $storageName => $storage) {
            $storageType = $storage['type'];

            match ($storageType) {
                Configuration::TYPE_DOCTRINE => $this->defineStorageDoctrine($containerBuilder, $storage, $storageName),
                Configuration::TYPE_FILE => $this->defineStorageFile($containerBuilder, $storage, $storageName),
                Configuration::TYPE_CUSTOM => $this->defineStorageCustom($containerBuilder, $storage, $storageName),
                default => throw new Exception(\sprintf('invalid storage type `%s`', $storageType)),
            };
        }
    }

    /** @param array<string, mixed> $storage */
    protected function defineStorageDoctrine(
        ContainerBuilder $containerBuilder,
        array            $storage,
        string           $storageName,
    ): void {
        $storageType = $storage['type'];
        $entityManager = $storage['entity_manager'] ?? null;

        if (null === $entityManager || '' === $entityManager) {
            throw new Exception(
                \sprintf('the `%s` config is mandatory for storage type `%s`', 'entity_manager', $storageType),
            );
        }

        [$entityManager] = $this->getEntityManagerAndConnection($storage);

        $this->defineStorageDoctrineConfig($containerBuilder, $storageName, $storage['config'] ?? []);

        $logger = $storage['logger'] ?? null;

        $definition = new Definition(
            Storage::class,
            [
                $this->getEntityManager($entityManager),
                new Reference($this->getStorageConfigId($storageName)),
                null === $logger ? $logger : new Reference($logger),
            ],
        );

        $storageServiceId = $this->getStorageId($storageName);

        $containerBuilder->setDefinition($storageServiceId, $definition);
    }

    /** @param array<string, mixed> $config */
    protected function defineStorageDoctrineConfig(
        ContainerBuilder $containerBuilder,
        string           $storageName,
        array            $config,
    ): void {
        $definition = new Definition(
            DoctrineConfig::class,
            [
                $config,
            ],
        );

        $storageServiceId = $this->getStorageConfigId($storageName);

        $containerBuilder->setDefinition($storageServiceId, $definition);
    }

    /** @param array<string, mixed> $storage */
    protected function defineStorageFile(
        ContainerBuilder $containerBuilder,
        array            $storage,
        string           $storageName,
    ): void {
        $storageType = $storage['type'];
        $file = $storage['file'] ?? null;

        if (null === $file || '' === $file) {
            throw new Exception(
                \sprintf('the `%s` config is mandatory for storage type `%s`', 'file', $storageType),
            );
        }

        $logger = $storage['logger'] ?? null;

        $definition = new Definition(
            FileStorage::class,
            [
                $file,
                null === $logger ? $logger : new Reference($logger),
            ],
        );

        $storageServiceId = $this->getStorageId($storageName);

        $containerBuilder->setDefinition($storageServiceId, $definition);
    }

    /** @param array<string, mixed> $storage */
    protected function defineStorageCustom(
        ContainerBuilder $containerBuilder,
        array            $storage,
        string           $storageName,
    ): void {
        $storageType = $storage['type'];
        $service = $storage['service'] ?? null;

        if (null === $service || '' === $service) {
            throw new Exception(
                \sprintf('the `%s` config is mandatory for storage type `%s`', 'service', $storageType),
            );
        }

        $storageServiceId = $this->getStorageId($storageName);

        $containerBuilder->setAlias($storageServiceId, $service);
    }

    /** @param array<string, mixed> $auditors */
    protected function defineAuditors(ContainerBuilder $containerBuilder, array $auditors): void
    {
        foreach ($auditors as $auditorName => $auditor) {
            [$entityManager, $connection] = $this->getEntityManagerAndConnection($auditor);

            $transactionProvider = $auditor['transaction_provider'];
            $logger = $auditor['logger'] ?? null;

            $this->defineAuditorConfig($containerBuilder, $auditorName, $auditor);

            $storages = \array_map(
                fn(string $storage) => new Reference($this->getStorageId($storage)),
                $auditor['synchronous_storages'],
            );

            $definition = new Definition(
                Auditor::class,
                [
                    new Reference($this->getAuditorConfigId($auditorName)),
                    $this->getEntityManager($entityManager),
                    $storages,
                    new Reference($transactionProvider),
                    null === $logger ? $logger : new Reference($logger),
                    new Reference(AnnotationReadService::class),
                ],
            );

            $definition->addTag('doctrine.event_listener', ['connection' => $connection, 'event' => Events::onFlush])
                ->addTag('doctrine.event_listener', ['connection' => $connection, 'event' => Events::postFlush]);

            $containerBuilder->setDefinition($this->getAuditorId($auditorName), $definition);
        }
    }

    /** @param array<string, mixed> $auditor */
    protected function defineAuditorConfig(ContainerBuilder $containerBuilder, string $auditorName, array $auditor): void
    {
        $definition = new Definition(
            AuditorConfig::class,
            [
                $auditor['ignored_fields'] ?? [],
            ],
        );

        $auditorConfigServiceId = $this->getAuditorConfigId($auditorName);

        $containerBuilder->setDefinition($auditorConfigServiceId, $definition);
    }

    /**
     * @param array<string, mixed> $auditors
     * @param array<string, mixed> $storages
     */
    protected function defineServices(ContainerBuilder $containerBuilder, array $auditors, array $storages): void
    {
        foreach ($auditors as $auditorName => $auditor) {
            foreach ($auditor['storages'] as $storageName) {
                if (false === isset($storages[$storageName])) {
                    throw new Exception(
                        \sprintf('could not find storage `%s` for auditor `%s`', $storageName, $auditorName),
                    );
                }

                $storage = $storages[$storageName];

                match ($storage['type']) {
                    Configuration::TYPE_DOCTRINE => $this->defineSchemaCommands(
                        $containerBuilder,
                        $auditorName,
                        $storageName,
                        $auditor,
                        $storage,
                    ),
                    default => null,
                };
            }
        }
    }

    /**
     * @param array<string, mixed> $auditor
     * @param array<string, mixed> $storage
     */
    protected function defineSchemaCommands(
        ContainerBuilder $containerBuilder,
        string           $auditorName,
        string           $storageName,
        array            $auditor,
        array            $storage,
    ): void {
        [$auditorEntityManager] = $this->getEntityManagerAndConnection($auditor);
        [$storageEntityManager, $storageConnection] = $this->getEntityManagerAndConnection($storage);

        $auditorEntityManagerReference = $this->getEntityManager($auditorEntityManager);
        $storageEntityManagerReference = $this->getEntityManager($storageEntityManager);

        $defineCommand = function (
            string $commandClass,
            string $commandName,
        ) use (
            $containerBuilder,
            $auditorName,
            $auditorEntityManagerReference,
            $storageEntityManagerReference
        ): void {
            $definition = new Definition(
                $commandClass,
                [
                    \sprintf('%s:schema:%s:%s', static::BASE_COMMAND_NAME, $commandName, $auditorName),
                    $auditorEntityManagerReference,
                    $storageEntityManagerReference,
                    new Reference(AnnotationReadService::class),
                ],
            );

            $definition->addTag('console.command');

            $containerBuilder->setDefinition(
                $this->getCommandId(\sprintf('%s.%s', $commandName, $auditorName)),
                $definition,
            );
        };

        $defineCommand(CreateCommand::class, 'create');

        $defineCommand(UpdateCommand::class, 'update');

        $definition = new Definition(
            DoctrineSchemaListener::class,
            [
                new Reference(AnnotationReadService::class),
                new Reference($this->getAuditorConfigId($auditorName)),
                new Reference($this->getStorageConfigId($storageName)),
            ],
        );

        $definition->addTag('doctrine.event_listener', ['connection' => $storageConnection, 'event' => ToolEvents::postGenerateSchemaTable])
            ->addTag('doctrine.event_listener', ['connection' => $storageConnection, 'event' => ToolEvents::postGenerateSchema]);

        $containerBuilder->setDefinition(
            $this->getCommandId(\sprintf('schema:listener.%s.%s', $auditorName, $storageName)),
            $definition,
        );
    }

    protected function getStorageId(string $name): string
    {
        return \sprintf('%s.storage.%s', static::BASE_SERVICE_ID, $name);
    }

    protected function getStorageConfigId(string $name): string
    {
        return \sprintf('%s.storage.%s.config', static::BASE_SERVICE_ID, $name);
    }

    protected function getAuditorId(string $name): string
    {
        return \sprintf('%s.auditor.%s', static::BASE_SERVICE_ID, $name);
    }

    protected function getAuditorConfigId(string $name): string
    {
        return \sprintf('%s.auditor.%s.config', static::BASE_SERVICE_ID, $name);
    }

    protected function getCommandId(string $name): string
    {
        return \sprintf('%s.command.%s', static::BASE_SERVICE_ID, $name);
    }

    protected function getEntityManager(string $name): Reference
    {
        return new Reference(\sprintf('doctrine.orm.%s_entity_manager', $name));
    }

    /**
     * @param array<string, mixed> $config
     * @return array{string, string}
     */
    protected function getEntityManagerAndConnection(array $config): array
    {
        $entityManager = $config['entity_manager'] ?? '';
        $connection = $config['connection'] ?? $entityManager;

        return [$entityManager, $connection];
    }
}
