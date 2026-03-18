<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\CreateCommand;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\UpdateCommand;
use PrecisionSoft\Doctrine\Audit\DependencyInjection\PrecisionSoftDoctrineAuditExtension;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Storage as DoctrineStorage;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class PrecisionSoftDoctrineAuditExtensionTest extends TestCase
{
    private const AUDITOR_NAME = 'main';
    private const TRANSACTION_PROVIDER = 'App\\TransactionProvider';

    private function buildContainer(array $config): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $extension = new PrecisionSoftDoctrineAuditExtension();
        $extension->load([$config], $containerBuilder);

        return $containerBuilder;
    }

    private function validDoctrineStorageConfig(string $storageName, string $entityManager, ?string $logger = null): array
    {
        $config = [
            'type' => 'doctrine',
            'entity_manager' => $entityManager,
        ];

        if (null !== $logger) {
            $config['logger'] = $logger;
        }

        return [$storageName => $config];
    }

    private function validFileStorageConfig(string $storageName, string $file, ?string $logger = null): array
    {
        $config = [
            'type' => 'file',
            'file' => $file,
        ];

        if (null !== $logger) {
            $config['logger'] = $logger;
        }

        return [$storageName => $config];
    }

    private function validCustomStorageConfig(string $storageName, string $service): array
    {
        return [
            $storageName => [
                'type' => 'custom',
                'service' => $service,
            ],
        ];
    }

    private function validAuditorConfig(array $storageNames, ?string $logger = null): array
    {
        $config = [
            'entity_manager' => 'default',
            'storages' => $storageNames,
            'transaction_provider' => static::TRANSACTION_PROVIDER,
        ];

        if (null !== $logger) {
            $config['logger'] = $logger;
        }

        return [static::AUDITOR_NAME => $config];
    }

    public function testDoctrineStorageWithoutLogger(): void
    {
        $storageName = 'audit_store';
        $entityManager = 'audit';

        $container = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig($storageName, $entityManager),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s', $storageName);

        static::assertTrue($container->hasDefinition($serviceId));

        $definition = $container->getDefinition($serviceId);

        static::assertSame(DoctrineStorage::class, $definition->getClass());

        $arguments = $definition->getArguments();

        static::assertInstanceOf(Reference::class, $arguments[0]);
        static::assertSame(
            \sprintf('doctrine.orm.%s_entity_manager', $entityManager),
            (string)$arguments[0],
        );
        static::assertNull($arguments[2]);
    }

    public function testDoctrineStorageWithLogger(): void
    {
        $storageName = 'audit_store';
        $logger = 'monolog.logger';

        $container = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig($storageName, 'audit', $logger),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $arguments = $container
            ->getDefinition(\sprintf('precision_soft_doctrine_audit.storage.%s', $storageName))
            ->getArguments();

        static::assertInstanceOf(Reference::class, $arguments[2]);
        static::assertSame($logger, (string)$arguments[2]);
    }

    public function testFileStorageWithoutLogger(): void
    {
        $storageName = 'file_store';
        $file = '/var/log/audit.log';

        $container = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, $file),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s', $storageName);

        static::assertTrue($container->hasDefinition($serviceId));

        $definition = $container->getDefinition($serviceId);

        static::assertSame(FileStorage::class, $definition->getClass());

        $arguments = $definition->getArguments();

        static::assertSame($file, $arguments[0]);
        static::assertNull($arguments[1]);
    }

    public function testFileStorageWithLogger(): void
    {
        $storageName = 'file_store';
        $logger = 'monolog.logger';

        $container = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, '/var/log/audit.log', $logger),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $arguments = $container
            ->getDefinition(\sprintf('precision_soft_doctrine_audit.storage.%s', $storageName))
            ->getArguments();

        static::assertInstanceOf(Reference::class, $arguments[1]);
        static::assertSame($logger, (string)$arguments[1]);
    }

    public function testCustomStorageDefinition(): void
    {
        $storageName = 'custom_store';
        $service = 'App\\CustomStorage';

        $container = $this->buildContainer([
            'storages' => $this->validCustomStorageConfig($storageName, $service),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s', $storageName);

        static::assertTrue($container->hasAlias($serviceId));
        static::assertSame($service, (string)$container->getAlias($serviceId));
    }

    public function testDoctrineStorageMissingEntityManagerThrows(): void
    {
        $this->expectException(Exception::class);

        $this->buildContainer([
            'storages' => [
                'audit_store' => [
                    'type' => 'doctrine',
                    'entity_manager' => '',
                ],
            ],
            'auditors' => $this->validAuditorConfig(['audit_store']),
        ]);
    }

    public function testFileStorageMissingFileThrows(): void
    {
        $this->expectException(Exception::class);

        $this->buildContainer([
            'storages' => [
                'file_store' => [
                    'type' => 'file',
                    'file' => '',
                ],
            ],
            'auditors' => $this->validAuditorConfig(['file_store']),
        ]);
    }

    public function testCustomStorageMissingServiceThrows(): void
    {
        $this->expectException(Exception::class);

        $this->buildContainer([
            'storages' => [
                'custom_store' => [
                    'type' => 'custom',
                ],
            ],
            'auditors' => $this->validAuditorConfig(['custom_store']),
        ]);
    }

    public function testAuditorDefinition(): void
    {
        $storageName = 'file_store';

        $container = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, '/var/log/audit.log'),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.auditor.%s', static::AUDITOR_NAME);

        static::assertTrue($container->hasDefinition($serviceId));
        static::assertSame(Auditor::class, $container->getDefinition($serviceId)->getClass());
    }

    public function testSchemaCommandsDefined(): void
    {
        $storageName = 'audit_store';

        $container = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig($storageName, 'audit'),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $createId = \sprintf('precision_soft_doctrine_audit.command.create.%s', static::AUDITOR_NAME);
        $updateId = \sprintf('precision_soft_doctrine_audit.command.update.%s', static::AUDITOR_NAME);

        static::assertTrue($container->hasDefinition($createId));
        static::assertTrue($container->hasDefinition($updateId));
        static::assertSame(CreateCommand::class, $container->getDefinition($createId)->getClass());
        static::assertSame(UpdateCommand::class, $container->getDefinition($updateId)->getClass());
    }

    public function testUndefinedStorageReferenceThrows(): void
    {
        $this->expectException(Exception::class);

        $this->buildContainer([
            'storages' => $this->validFileStorageConfig('existing_store', '/var/log/audit.log'),
            'auditors' => $this->validAuditorConfig(['nonexistent_store']),
        ]);
    }
}
