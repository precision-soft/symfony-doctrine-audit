<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Auditor\Auditor;
use PrecisionSoft\Doctrine\Audit\Command\Audit\PurgeCommand;
use PrecisionSoft\Doctrine\Audit\Command\Audit\ReadCommand;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\CreateCommand;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\UpdateCommand;
use PrecisionSoft\Doctrine\Audit\Contract\AuditPurgerInterface;
use PrecisionSoft\Doctrine\Audit\Contract\AuditReaderInterface;
use PrecisionSoft\Doctrine\Audit\DependencyInjection\PrecisionSoftDoctrineAuditExtension;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Storage as DoctrineStorage;
use PrecisionSoft\Doctrine\Audit\Storage\FileAuditReader;
use PrecisionSoft\Doctrine\Audit\Storage\FileStorage;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class PrecisionSoftDoctrineAuditExtensionTest extends TestCase
{
    private const AUDITOR_NAME = 'main';
    private const TRANSACTION_PROVIDER = 'App\\TransactionProvider';

    public function testDoctrineStorageWithoutLogger(): void
    {
        $storageName = 'audit_store';
        $entityManager = 'audit';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig($storageName, $entityManager),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s', $storageName);

        static::assertSame(true, $containerBuilder->hasDefinition($serviceId));

        $definition = $containerBuilder->getDefinition($serviceId);

        static::assertSame(DoctrineStorage::class, $definition->getClass());

        $arguments = $definition->getArguments();

        static::assertInstanceOf(Reference::class, $arguments[0]);
        static::assertSame(
            \sprintf('doctrine.orm.%s_entity_manager', $entityManager),
            (string)$arguments[0],
        );
        static::assertSame(null, $arguments[2]);
    }

    public function testDoctrineStorageWithLogger(): void
    {
        $storageName = 'audit_store';
        $logger = 'monolog.logger';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig($storageName, 'audit', $logger),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $arguments = $containerBuilder
            ->getDefinition(\sprintf('precision_soft_doctrine_audit.storage.%s', $storageName))
            ->getArguments();

        static::assertInstanceOf(Reference::class, $arguments[2]);
        static::assertSame($logger, (string)$arguments[2]);
    }

    public function testFileStorageWithoutLogger(): void
    {
        $storageName = 'file_store';
        $file = '/var/log/audit.log';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, $file),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s', $storageName);

        static::assertSame(true, $containerBuilder->hasDefinition($serviceId));

        $definition = $containerBuilder->getDefinition($serviceId);

        static::assertSame(FileStorage::class, $definition->getClass());

        $arguments = $definition->getArguments();

        static::assertSame($file, $arguments[0]);
        static::assertSame(null, $arguments[1]);
    }

    public function testFileStorageWithLogger(): void
    {
        $storageName = 'file_store';
        $logger = 'monolog.logger';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, '/var/log/audit.log', $logger),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $arguments = $containerBuilder
            ->getDefinition(\sprintf('precision_soft_doctrine_audit.storage.%s', $storageName))
            ->getArguments();

        static::assertInstanceOf(Reference::class, $arguments[1]);
        static::assertSame($logger, (string)$arguments[1]);
    }

    public function testFileStorageRegistersAReaderOnTheSameFile(): void
    {
        $storageName = 'file_store';
        $file = '/var/log/audit.log';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, $file),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s.reader', $storageName);

        static::assertTrue($containerBuilder->hasDefinition($serviceId));

        $definition = $containerBuilder->getDefinition($serviceId);

        static::assertSame(FileAuditReader::class, $definition->getClass());
        static::assertSame([$file], $definition->getArguments());
    }

    public function testASingleFileStorageAliasesTheReaderAndPurgerContracts(): void
    {
        $storageName = 'file_store';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, '/var/log/audit.log'),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $readerServiceId = \sprintf('precision_soft_doctrine_audit.storage.%s.reader', $storageName);

        static::assertTrue($containerBuilder->hasAlias(AuditReaderInterface::class));
        static::assertTrue($containerBuilder->hasAlias(AuditPurgerInterface::class));
        static::assertSame($readerServiceId, (string)$containerBuilder->getAlias(AuditReaderInterface::class));
        static::assertSame($readerServiceId, (string)$containerBuilder->getAlias(AuditPurgerInterface::class));
    }

    public function testTwoFileStoragesLeaveTheContractsUnaliased(): void
    {
        $containerBuilder = $this->buildContainer([
            'storages' => [
                ...$this->validFileStorageConfig('first_file', '/var/log/first.log'),
                ...$this->validFileStorageConfig('second_file', '/var/log/second.log'),
            ],
            'auditors' => $this->validAuditorConfig(['first_file', 'second_file']),
        ]);

        static::assertFalse($containerBuilder->hasAlias(AuditReaderInterface::class));
        static::assertFalse($containerBuilder->hasAlias(AuditPurgerInterface::class));
        static::assertTrue(
            $containerBuilder->hasDefinition('precision_soft_doctrine_audit.storage.first_file.reader'),
        );
        static::assertTrue(
            $containerBuilder->hasDefinition('precision_soft_doctrine_audit.storage.second_file.reader'),
        );
    }

    public function testADoctrineOnlySetupLeavesTheContractsUnaliased(): void
    {
        $containerBuilder = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig('doctrine_store', 'default'),
            'auditors' => $this->validAuditorConfig(['doctrine_store']),
        ]);

        static::assertFalse($containerBuilder->hasAlias(AuditReaderInterface::class));
        static::assertFalse($containerBuilder->hasAlias(AuditPurgerInterface::class));
    }

    public function testFileStorageRegistersReadAndPurgeCommands(): void
    {
        $storageName = 'file_store';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, '/var/log/audit.log'),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        foreach ([ReadCommand::class => 'read', PurgeCommand::class => 'purge'] as $commandClass => $commandName) {
            $serviceId = \sprintf(
                'precision_soft_doctrine_audit.command.%s.%s.%s',
                $commandName,
                static::AUDITOR_NAME,
                $storageName,
            );

            static::assertTrue($containerBuilder->hasDefinition($serviceId));

            $definition = $containerBuilder->getDefinition($serviceId);

            static::assertSame($commandClass, $definition->getClass());
            static::assertArrayHasKey('console.command', $definition->getTags());

            $arguments = $definition->getArguments();

            static::assertSame(
                \sprintf(
                    'precision-soft:doctrine:audit:%s:%s:%s',
                    $commandName,
                    static::AUDITOR_NAME,
                    $storageName,
                ),
                $arguments[0],
            );
            static::assertInstanceOf(Reference::class, $arguments[1]);
            static::assertSame(
                \sprintf('precision_soft_doctrine_audit.storage.%s.reader', $storageName),
                (string)$arguments[1],
            );
        }
    }

    public function testCustomStorageDefinition(): void
    {
        $storageName = 'custom_store';
        $service = 'App\\CustomStorage';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validCustomStorageConfig($storageName, $service),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.storage.%s', $storageName);

        static::assertSame(true, $containerBuilder->hasAlias($serviceId));
        static::assertSame($service, (string)$containerBuilder->getAlias($serviceId));
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

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validFileStorageConfig($storageName, '/var/log/audit.log'),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $serviceId = \sprintf('precision_soft_doctrine_audit.auditor.%s', static::AUDITOR_NAME);

        static::assertSame(true, $containerBuilder->hasDefinition($serviceId));
        static::assertSame(Auditor::class, $containerBuilder->getDefinition($serviceId)->getClass());
    }

    public function testSchemaCommandsDefined(): void
    {
        $storageName = 'audit_store';

        $containerBuilder = $this->buildContainer([
            'storages' => $this->validDoctrineStorageConfig($storageName, 'audit'),
            'auditors' => $this->validAuditorConfig([$storageName]),
        ]);

        $createId = \sprintf('precision_soft_doctrine_audit.command.create.%s.%s', static::AUDITOR_NAME, $storageName);
        $updateId = \sprintf('precision_soft_doctrine_audit.command.update.%s.%s', static::AUDITOR_NAME, $storageName);

        static::assertSame(true, $containerBuilder->hasDefinition($createId));
        static::assertSame(true, $containerBuilder->hasDefinition($updateId));
        static::assertSame(CreateCommand::class, $containerBuilder->getDefinition($createId)->getClass());
        static::assertSame(UpdateCommand::class, $containerBuilder->getDefinition($updateId)->getClass());
    }

    public function testUndefinedStorageReferenceThrows(): void
    {
        $this->expectException(Exception::class);

        $this->buildContainer([
            'storages' => $this->validFileStorageConfig('existing_store', '/var/log/audit.log'),
            'auditors' => $this->validAuditorConfig(['nonexistent_store']),
        ]);
    }

    public function testTwoDoctrineStoragesOnOneAuditorEachGetTheirOwnSchemaCommands(): void
    {
        $containerBuilder = $this->buildContainer([
            'storages' => \array_merge(
                $this->validDoctrineStorageConfig('audit_one', 'audit_em_one'),
                $this->validDoctrineStorageConfig('audit_two', 'audit_em_two'),
            ),
            'auditors' => $this->validAuditorConfig(['audit_one', 'audit_two']),
        ]);

        $commandNames = [];

        foreach (['audit_one', 'audit_two'] as $storageName) {
            foreach (['create', 'update'] as $action) {
                $serviceId = \sprintf(
                    'precision_soft_doctrine_audit.command.%s.%s.%s',
                    $action,
                    static::AUDITOR_NAME,
                    $storageName,
                );

                static::assertTrue(
                    $containerBuilder->hasDefinition($serviceId),
                    \sprintf('missing schema command `%s`', $serviceId),
                );

                $commandNames[] = $containerBuilder->getDefinition($serviceId)->getArgument(0);
            }
        }

        static::assertSame($commandNames, \array_unique($commandNames));
    }

    public function testOmittingStoragesReportsTheMissingOptionInsteadOfCrashing(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/storages/');

        $this->buildContainer([
            'storages' => $this->validFileStorageConfig('file_store', '/var/log/audit.log'),
            'auditors' => [
                static::AUDITOR_NAME => [
                    'entity_manager' => 'default',
                    'transaction_provider' => static::TRANSACTION_PROVIDER,
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $configuration */
    private function buildContainer(array $configuration): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $precisionSoftDoctrineAuditExtension = new PrecisionSoftDoctrineAuditExtension();
        $precisionSoftDoctrineAuditExtension->load([$configuration], $containerBuilder);

        return $containerBuilder;
    }

    /** @return array<string, array<string, string>> */
    private function validDoctrineStorageConfig(string $storageName, string $entityManager, ?string $logger = null): array
    {
        $configuration = [
            'type' => 'doctrine',
            'entity_manager' => $entityManager,
        ];

        if (null !== $logger) {
            $configuration['logger'] = $logger;
        }

        return [$storageName => $configuration];
    }

    /** @return array<string, array<string, string>> */
    private function validFileStorageConfig(string $storageName, string $file, ?string $logger = null): array
    {
        $configuration = [
            'type' => 'file',
            'file' => $file,
        ];

        if (null !== $logger) {
            $configuration['logger'] = $logger;
        }

        return [$storageName => $configuration];
    }

    /** @return array<string, array<string, string>> */
    private function validCustomStorageConfig(string $storageName, string $service): array
    {
        return [
            $storageName => [
                'type' => 'custom',
                'service' => $service,
            ],
        ];
    }

    /**
     * @param string[] $storageNames
     * @return array<string, array<string, mixed>>
     */
    private function validAuditorConfig(array $storageNames, ?string $logger = null): array
    {
        $configuration = [
            'entity_manager' => 'default',
            'storages' => $storageNames,
            'transaction_provider' => static::TRANSACTION_PROVIDER,
        ];

        if (null !== $logger) {
            $configuration['logger'] = $logger;
        }

        return [static::AUDITOR_NAME => $configuration];
    }
}
