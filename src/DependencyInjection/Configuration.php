<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\DependencyInjection;

use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const TYPE_DOCTRINE = 'doctrine';
    public const TYPE_FILE = 'file';
    public const TYPE_CUSTOM = 'custom';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('precision_soft_doctrine_audit');

        $nodeBuilder = $treeBuilder->getRootNode()
            ->children();

        $this->attachStorages($nodeBuilder);

        $this->attachAuditors($nodeBuilder);

        return $treeBuilder;
    }

    private function attachStorages(NodeBuilder $nodeBuilder): void
    {
        /** @var ArrayNodeDefinition $storages */
        $storages = $nodeBuilder->arrayNode('storages')->isRequired()
            ->cannotBeEmpty()
            ->useAttributeAsKey('name')
            ->arrayPrototype();

        $types = [self::TYPE_DOCTRINE, self::TYPE_FILE, self::TYPE_CUSTOM];

        $storages->children()
            ->scalarNode('name')->end()
            ->enumNode('type')->values($types)->isRequired()->end()
            ->scalarNode('entity_manager')->end()/** @info for doctrine */
            ->scalarNode('connection')->end()/** @info for doctrine */
            ->scalarNode('file')->end()/** @info for file */
            ->scalarNode('service')->end()/** @info for custom */
            ->scalarNode('logger')->end()
            ->arrayNode('config')->scalarPrototype()->end()->end();
        /** @info generic node with variable content for extra configs */
    }

    private function attachAuditors(NodeBuilder $nodeBuilder): void
    {
        /** @var ArrayNodeDefinition $auditors */
        $auditors = $nodeBuilder->arrayNode('auditors')->isRequired()
            ->cannotBeEmpty()
            ->useAttributeAsKey('name')
            ->arrayPrototype();

        $auditors->beforeNormalization()->always(
            function (array $auditor) {
                $auditor['synchronous_storages'] ??= $auditor['storages'];

                $missingStorages = \array_diff($auditor['synchronous_storages'], $auditor['storages']);
                if (false === empty($missingStorages)) {
                    throw new Exception(
                        \sprintf(
                            'the synchronous storages `%s` were not found in the storages list `%s`',
                            \implode(', ', $missingStorages),
                            \implode(', ', $auditor['storages']),
                        ),
                    );
                }

                return $auditor;
            },
        );

        $auditors->children()
            ->scalarNode('name')->end()
            ->scalarNode('entity_manager')->defaultValue('default')->end()
            ->scalarNode('connection')->end()/** @info will use the name of the entity manager if this is not set */
            /** @info the complete list of storage used by the auditor */
            ->arrayNode('storages')->isRequired()->cannotBeEmpty()->scalarPrototype()->end()->end()
            /** @info a part of the storages list that are used on Auditor::save */
            ->arrayNode('synchronous_storages')->cannotBeEmpty()->scalarPrototype()->end()->end()
            ->scalarNode('transaction_provider')->isRequired()->end()
            ->scalarNode('logger')->end()
            ->arrayNode('ignored_fields')->scalarPrototype()->end()->end();
    }
}
