<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Lock\LockFactory;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    public function process(ContainerBuilder $container): void
    {
        // The resource owner keeps the OAuth state of one sign-in, and has no reset. A FrankenPHP worker would share it between users.
        $container->getDefinition('hwi_oauth.resource_owner.github')->setShared(false);

        $container->getDefinition('messenger.transport.doctrine.pg_notify_on_idle_listener')->replaceArgument(0, new Reference('monolog.logger.messenger_idle'));

        // framework.rate_limiter refuses a lock factory while framework.lock is off (config/packages/lock.yaml).
        foreach (array_keys($container->findTaggedServiceIds('rate_limiter')) as $id) {
            $definition = $container->getDefinition($id);

            if ($definition instanceof ChildDefinition && 'limiter' === $definition->getParent()) {
                $definition->replaceArgument(2, new Reference(LockFactory::class));
            }
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test', 'e2e'];
    }
}
