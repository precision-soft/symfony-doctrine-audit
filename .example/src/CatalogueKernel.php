<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use PrecisionSoft\Doctrine\Audit\Example\Service\Catalogue;
use PrecisionSoft\Doctrine\Audit\Example\Service\CatalogueTransactionProvider;
use PrecisionSoft\Doctrine\Audit\PrecisionSoftDoctrineAuditBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The application around the nomenclator: the catalogue in one database, its audit trail in a second one, and the
 * same trail mirrored into a jsonl file. The two databases are the bundle's hard rule - audit tables carry the entity
 * tables' names, so pointing the trail at the catalogue would replace it.
 */
class CatalogueKernel extends Kernel
{
    use MicroKernelTrait;

    public const SCHEMA_CREATE_COMMAND = 'example.command.schema_create';
    public const SCHEMA_UPDATE_COMMAND = 'example.command.schema_update';
    public const AUDIT_READ_COMMAND = 'example.command.audit_read';
    public const AUDIT_PURGE_COMMAND = 'example.command.audit_purge';

    protected const COMMAND_ALIAS_MAP = [
        self::SCHEMA_CREATE_COMMAND => 'precision_soft_doctrine_audit.command.create.catalogue.trail',
        self::SCHEMA_UPDATE_COMMAND => 'precision_soft_doctrine_audit.command.update.catalogue.trail',
        self::AUDIT_READ_COMMAND => 'precision_soft_doctrine_audit.command.read.catalogue.jsonl',
        self::AUDIT_PURGE_COMMAND => 'precision_soft_doctrine_audit.command.purge.catalogue.jsonl',
    ];

    public function __construct(
        string $environment,
        protected readonly string $catalogueUrl,
        protected readonly string $trailUrl,
        protected readonly string $auditFile,
    ) {
        parent::__construct($environment, false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new PrecisionSoftDoctrineAuditBundle()];
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__) . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__) . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $containerConfigurator): void
    {
        $containerConfigurator->extension('framework', ['secret' => 'product-catalogue', 'test' => true]);

        $containerConfigurator->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'catalogue',
                'connections' => [
                    'catalogue' => ['url' => $this->catalogueUrl],
                    'trail' => ['url' => $this->trailUrl],
                ],
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
                'default_entity_manager' => 'catalogue',
                'entity_managers' => [
                    'catalogue' => [
                        'connection' => 'catalogue',
                        'mappings' => [
                            'Catalogue' => [
                                'type' => 'attribute',
                                'dir' => __DIR__ . '/Entity',
                                'prefix' => 'PrecisionSoft\Doctrine\Audit\Example\Entity',
                                'is_bundle' => false,
                            ],
                        ],
                    ],
                    /* the trail's manager maps the same classes: the audit schema is built from them, one audit table per audited entity table */
                    'trail' => [
                        'connection' => 'trail',
                        'mappings' => [
                            'Catalogue' => [
                                'type' => 'attribute',
                                'dir' => __DIR__ . '/Entity',
                                'prefix' => 'PrecisionSoft\Doctrine\Audit\Example\Entity',
                                'is_bundle' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $containerConfigurator->extension('precision_soft_doctrine_audit', [
            'storages' => [
                'trail' => [
                    'type' => 'doctrine',
                    'entity_manager' => 'trail',
                    'config' => ['transaction_table_name' => 'audit_transaction'],
                ],
                'jsonl' => [
                    'type' => 'file',
                    'file' => $this->auditFile,
                ],
            ],
            'auditors' => [
                'catalogue' => [
                    'entity_manager' => 'catalogue',
                    'storages' => ['trail', 'jsonl'],
                    'transaction_provider' => CatalogueTransactionProvider::class,
                    'ignored_fields' => ['modified'],
                ],
            ],
        ]);

        $services = $containerConfigurator->services();

        $services->set(CatalogueTransactionProvider::class)->public();
        $services->set(Catalogue::class)
            ->args([service('doctrine.orm.catalogue_entity_manager')])
            ->public();

        /*
         * The bundle's commands are private, as console commands should be - the console finds them by their tag.
         * A test drives them directly, so the example aliases the four it uses; this is also how an application
         * reaches one from its own code.
         */
        foreach (static::COMMAND_ALIAS_MAP as $alias => $serviceId) {
            $services->alias($alias, $serviceId)->public();
        }
    }
}
