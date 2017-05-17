<?php

namespace Silksh\BigLogBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('silksh_big_log');
        $rootNode
            ->children()
                ->variableNode('redis_url')->end()
                ->variableNode('redis_namespace')->end()
                ->arrayNode('db')
                    ->children()
                    ->variableNode('driver')->end()
                    ->variableNode('host')->end()
                    ->variableNode('port')->end()
                    ->variableNode('dbname')->end()
                    ->variableNode('schema')->end()
                    ->variableNode('user')->end()
                    ->variableNode('password')->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
