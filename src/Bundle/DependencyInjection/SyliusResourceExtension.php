<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Bundle\ResourceBundle\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Driver\Doctrine\DoctrineODMDriver;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Driver\Doctrine\DoctrineORMDriver;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Driver\Doctrine\DoctrinePHPCRDriver;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Driver\DriverProvider;
use Sylius\Bundle\ResourceBundle\Form\Type\DefaultResourceType;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Component\Resource\Factory\Factory;
use Sylius\Component\Resource\Metadata\Metadata;
use Sylius\Component\Resource\Metadata\Resource;
use Sylius\Component\Resource\Metadata\Resource as ResourceMetadata;
use Sylius\Component\Resource\Reflection\ClassReflection;
use Sylius\Component\Resource\State\ProcessorInterface;
use Sylius\Component\Resource\State\ProviderInterface;
use Sylius\Component\Resource\State\ResponderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class SyliusResourceExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $loader->load('services.xml');

        /** @var array $bundles */
        $bundles = $container->getParameter('kernel.bundles');
        if (array_key_exists('SyliusGridBundle', $bundles)) {
            $loader->load('services/integrations/grid.xml');
        }

        if ($config['translation']['enabled']) {
            $loader->load('services/integrations/translation.xml');

            $container->setAlias('sylius.translation_locale_provider', $config['translation']['locale_provider'])->setPublic(true);
        }

        $container->setParameter('sylius.resource.mapping', $config['mapping']);
        $container->setParameter('sylius.resource.settings', $config['settings']);
        $container->setAlias('sylius.resource_controller.authorization_checker', $config['authorization_checker']);

        $this->autoRegisterResources($config, $container);

        $this->loadPersistence($config['drivers'], $config['resources'], $loader);
        $this->loadResources($config['resources'], $container);

        $container->registerForAutoconfiguration(ProviderInterface::class)
            ->addTag('sylius.state_provider')
        ;

        $container->registerForAutoconfiguration(ProcessorInterface::class)
            ->addTag('sylius.state_processor')
        ;

        $container->registerForAutoconfiguration(ResponderInterface::class)
            ->addTag('sylius.state_responder')
        ;

        $container->addObjectResource(Metadata::class);
        $container->addObjectResource(DriverProvider::class);
        $container->addObjectResource(DoctrineORMDriver::class);
        $container->addObjectResource(DoctrineODMDriver::class);
        $container->addObjectResource(DoctrinePHPCRDriver::class);
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        $configuration = new Configuration();

        $container->addObjectResource($configuration);

        return $configuration;
    }

    public function prepend(ContainerBuilder $container): void
    {
        $config = ['body_listener' => ['enabled' => true]];
        $container->prependExtensionConfig('fos_rest', $config);
    }

    private function autoRegisterResources(array &$config, ContainerBuilder $container): void
    {
        /** @var array $resources */
        $resources = $config['resources'];

        /** @var array $mapping */
        $mapping = $container->getParameter('sylius.resource.mapping');
        $paths = $mapping['paths'] ?? [];

        /** @var class-string $className */
        foreach (ClassReflection::getResourcesByPaths($paths) as $className) {
            $resourceAttributes = ClassReflection::getClassAttributes($className, ResourceMetadata::class);

            foreach ($resourceAttributes as $resourceAttribute) {
                /** @var ResourceMetadata $resource */
                $resource = $resourceAttribute->newInstance();
                $resourceAlias = $this->getResourceAlias($resource);

                if ($resources[$resourceAlias] ?? false) {
                    continue;
                }

                $resources[$resourceAlias] = [
                    'classes' => [
                        'model' => $className,
                        'controller' => ResourceController::class,
                        'factory' => Factory::class,
                        'form' => DefaultResourceType::class,
                    ],
                    'driver' => 'doctrine/orm',
                ];
            }
        }

        $config['resources'] = $resources;
    }

    private function getResourceAlias(Resource $resource): string
    {
        return $resource->getAlias();
    }

    private function loadPersistence(array $drivers, array $resources, LoaderInterface $loader): void
    {
        $integrateDoctrine = array_reduce($drivers, function (bool $result, string $driver): bool {
            return $result || in_array($driver, [SyliusResourceBundle::DRIVER_DOCTRINE_ORM, SyliusResourceBundle::DRIVER_DOCTRINE_PHPCR_ODM, SyliusResourceBundle::DRIVER_DOCTRINE_MONGODB_ODM], true);
        }, false);

        if ($integrateDoctrine) {
            $loader->load('services/integrations/doctrine.xml');
        }

        foreach ($resources as $alias => $resource) {
            if (false === $resource['driver']) {
                break;
            }

            if (!in_array($resource['driver'], $drivers, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Resource "%s" uses driver "%s", but this driver has not been enabled.',
                    $alias,
                    $resource['driver'],
                ));
            }
        }

        foreach ($drivers as $driver) {
            if (in_array($driver, [SyliusResourceBundle::DRIVER_DOCTRINE_PHPCR_ODM, SyliusResourceBundle::DRIVER_DOCTRINE_MONGODB_ODM], true)) {
                trigger_deprecation(
                    'sylius/resource-bundle',
                    '1.3',
                    'The "%s" driver is deprecated. Doctrine MongoDB and PHPCR will no longer be supported in 2.0.',
                    $driver,
                );
            }

            $loader->load(sprintf('services/integrations/%s.xml', $driver));
        }
    }

    private function loadResources(array $loadedResources, ContainerBuilder $container): void
    {
        /** @var array<string, array> $resources */
        $resources = $container->hasParameter('sylius.resources') ? $container->getParameter('sylius.resources') : [];

        foreach ($loadedResources as $alias => $resourceConfig) {
            $metadata = Metadata::fromAliasAndConfiguration($alias, $resourceConfig);

            $resources[$alias] = $resourceConfig;
            $container->setParameter('sylius.resources', $resources);

            if ($metadata->getDriver()) {
                DriverProvider::get($metadata)->load($container, $metadata);
            }

            if ($metadata->hasParameter('translation')) {
                $alias .= '_translation';
                $resourceConfig = array_merge(['driver' => $resourceConfig['driver']], $resourceConfig['translation']);

                $resources[$alias] = $resourceConfig;
                $container->setParameter('sylius.resources', $resources);

                $metadata = Metadata::fromAliasAndConfiguration($alias, $resourceConfig);

                if ($metadata->getDriver()) {
                    DriverProvider::get($metadata)->load($container, $metadata);
                }
            }
        }
    }
}
