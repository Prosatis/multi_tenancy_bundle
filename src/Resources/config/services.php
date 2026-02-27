<?php

use Hakam\MultiTenancyBundle\Adapter\DefaultDsnGenerator;
use Hakam\MultiTenancyBundle\Adapter\Doctrine\DoctrineTenantConfigProvider;
use Hakam\MultiTenancyBundle\Adapter\Doctrine\DoctrineTenantDatabaseManager;
use Hakam\MultiTenancyBundle\Adapter\Doctrine\TenantDBALConnectionGenerator;
use Hakam\MultiTenancyBundle\Command\CreateDatabaseCommand;
use Hakam\MultiTenancyBundle\Command\DiffCommand;
use Hakam\MultiTenancyBundle\Command\LoadTenantFixtureCommand;
use Hakam\MultiTenancyBundle\Command\MigrateCommand;
use Hakam\MultiTenancyBundle\Context\TenantContext;
use Hakam\MultiTenancyBundle\Context\TenantContextInterface;
use Hakam\MultiTenancyBundle\Doctrine\ORM\TenantEntityManager;
use Hakam\MultiTenancyBundle\Event\SwitchDbEvent;
use Hakam\MultiTenancyBundle\Event\TenantSwitchedEvent;
use Hakam\MultiTenancyBundle\EventListener\DbSwitchEventListener;
use Hakam\MultiTenancyBundle\Port\TenantConfigProviderInterface;
use Hakam\MultiTenancyBundle\Port\TenantDatabaseManagerInterface;
use Hakam\MultiTenancyBundle\Purger\TenantORMPurgerFactory;
use Hakam\MultiTenancyBundle\Services\TenantDbConfigurationInterface;
use Hakam\MultiTenancyBundle\Services\TenantFixtureLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Doctrine tenant config provider
    $services->set('hakam_tenant_config_provider.doctrine', DoctrineTenantConfigProvider::class)
        ->private()
        ->autowire()
        ->autoconfigure()
        ->arg('$entityManager', service('doctrine.orm.entity_manager'))
        ->arg('$dbClassName', 'dbClassName')
        ->arg('$dbIdentifier', 'id');

    $services->alias(DoctrineTenantConfigProvider::class, 'hakam_tenant_config_provider.doctrine')
        ->private();

    $services->alias(TenantConfigProviderInterface::class, DoctrineTenantConfigProvider::class)
        ->private();

    // Tenant fixture loader
    $services->set('hakam_tenant_fixtures_loader.service', TenantFixtureLoader::class)
        ->private()
        ->args([tagged_iterator('tenant_fixture')]);

    $services->alias(TenantFixtureLoader::class, 'hakam_tenant_fixtures_loader.service')
        ->private();

    // DSN generator and DB connection generator
    $services->set(DefaultDsnGenerator::class)
        ->private()
        ->autowire();

    $services->set(TenantDBALConnectionGenerator::class)
        ->private()
        ->autowire()
        ->autoconfigure();

    // Tenant database manager
    $services->set(DoctrineTenantDatabaseManager::class)
        ->private()
        ->autowire();

    $services->alias(TenantDatabaseManagerInterface::class, DoctrineTenantDatabaseManager::class)
        ->private();

    // DB switch event listener
    $services->set(DbSwitchEventListener::class)
        ->tag('kernel.event_listener', ['event' => SwitchDbEvent::class, 'method' => 'onSwitchDb'])
        ->args([
            service('doctrine'),
            service(TenantConfigProviderInterface::class),
            service('tenant_entity_manager'),
            '%env(DATABASE_URL)%',
            service('event_dispatcher'),
        ]);

    // Commands
    $services->set(DiffCommand::class)
        ->tag('console.command')
        ->args([
            service('Doctrine\Persistence\ManagerRegistry'),
            service('service_container'),
            service('event_dispatcher'),
            service(TenantDatabaseManagerInterface::class),
        ]);

    $services->set('Symfony\Component\Console\Application')
        ->public();

    $services->set(CreateDatabaseCommand::class)
        ->tag('console.command')
        ->args([
            service(TenantDatabaseManagerInterface::class),
            service('event_dispatcher'),
        ]);

    $services->set(MigrateCommand::class)
        ->tag('console.command')
        ->args([
            service('Doctrine\Persistence\ManagerRegistry'),
            service('service_container'),
            service('event_dispatcher'),
            service(TenantDatabaseManagerInterface::class),
        ]);

    $services->set(LoadTenantFixtureCommand::class)
        ->tag('console.command')
        ->args([
            service('Doctrine\Persistence\ManagerRegistry'),
            service('service_container'),
            service('event_dispatcher'),
            service('hakam_tenant_fixtures_loader.service'),
            tagged_iterator('doctrine.fixtures.purger_factory', 'alias'),
        ]);

    // Tenant DB interface
    $services->set('tenant_db_interface', TenantDbConfigurationInterface::class)
        ->public();

    // Tenant entity manager
    $services->set('tenant_entity_manager', TenantEntityManager::class)
        ->public()
        ->args([service('doctrine.orm.tenant_entity_manager')]);

    $services->alias(TenantEntityManager::class, 'tenant_entity_manager');

    // Tenant purger factory
    $services->set('hakam.tenant_purger_factory', TenantORMPurgerFactory::class)
        ->tag('doctrine.fixtures.purger_factory', ['alias' => 'tenant_default']);

    // Tenant context
    $services->set(TenantContext::class)
        ->public()
        ->tag('kernel.event_listener', ['event' => TenantSwitchedEvent::class, 'method' => 'onTenantSwitched'])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->alias(TenantContextInterface::class, TenantContext::class)
        ->public();
};
