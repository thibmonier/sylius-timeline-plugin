<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class TmonierSyliusTimelineExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{record_emails: bool, enabled_workflows: list<string>, driver: string, resources: array<string, mixed>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_timeline', $config['driver'], $config['resources'], $container);

        $container->setParameter('tmonier_timeline.record_emails', $config['record_emails']);
        $container->setParameter('tmonier_timeline.enabled_workflows', array_values(array_unique($config['enabled_workflows'])));

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Grids are processed before this extension is loaded, so the model class parameter
        // has to exist at prepend time.
        /** @var array{resources: array<string, array{classes: array{model: string}}>} $config */
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        foreach ($config['resources'] as $name => $resource) {
            $container->setParameter(sprintf('tmonier_timeline.model.%s.class', $name), $resource['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusTimelinePlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusTimelinePlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusTimelinePlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusTimelinePlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
