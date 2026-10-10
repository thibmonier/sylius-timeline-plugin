<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Core\OrderShippingTransitions;
use Sylius\Component\Order\OrderTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Shipping\ShipmentTransitions;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntry;
use Tmonier\SyliusTimelinePlugin\Entity\TimelineEntryInterface;
use Tmonier\SyliusTimelinePlugin\Repository\TimelineEntryRepository;

final class Configuration implements ConfigurationInterface
{
    public const DEFAULT_WORKFLOWS = [
        OrderTransitions::GRAPH,
        OrderPaymentTransitions::GRAPH,
        OrderShippingTransitions::GRAPH,
        PaymentTransitions::GRAPH,
        ShipmentTransitions::GRAPH,
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_timeline');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('record_emails')
                    ->info('Record the e-mails sent by the Sylius mailer about an order.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('enabled_workflows')
                    ->info('Symfony workflows whose transitions are recorded, when their subject is an order, a payment or a shipment.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(self::DEFAULT_WORKFLOWS)
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('timeline_entry')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(TimelineEntry::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(TimelineEntryInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                        ->scalarNode('repository')->defaultValue(TimelineEntryRepository::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
