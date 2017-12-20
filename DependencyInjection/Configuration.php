<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/configuration.html}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $builder = new TreeBuilder();
        $root = $builder->root('trade');

        $root
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('btc_markets')
                    ->canBeEnabled()
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->always()
                        ->then(function ($v) {
                            if (!$v['enabled']) {
                                $v['python_bin_path'] = '-';
                                $v['api_key'] = '-';
                                $v['private_key'] = '-';
                            } elseif (!(isset($v['python_bin_path']) && $v['python_bin_path'])) {
                                $v['python_bin_path'] = exec('which python');
                            }

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->scalarNode('python_bin_path')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('api_key')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('private_key')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
            ->end();

        return $builder;
    }
}
