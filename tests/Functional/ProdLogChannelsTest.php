<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProdLogChannelsTest extends KernelTestCase
{
    #[TestDox('In prod, no handler takes the channel mcp')]
    public function testNoHandlerTakesTheMcpChannel(): void
    {
        $container = self::prodContainerBuilder();

        self::assertContains('monolog.handler.main', self::handlerIds($container, 'monolog.logger'));
        self::assertSame([], self::handlerIds($container, 'monolog.logger.mcp'));
    }

    /**
     * @return list<string>
     */
    private static function handlerIds(ContainerBuilder $container, string $loggerId): array
    {
        $handlerIds = [];

        foreach ($container->getDefinition($loggerId)->getMethodCalls() as [$method, $arguments]) {
            if ('pushHandler' === $method) {
                $handlerIds[] = (string) $arguments[0];
            }
        }

        return $handlerIds;
    }

    /**
     * Compiled as `debug:config` does: the test kernel cannot reach private services of another environment.
     */
    private static function prodContainerBuilder(): ContainerBuilder
    {
        $build = \Closure::bind(function (): ContainerBuilder {
            $this->initializeBundles();

            return $this->buildContainer();
        }, new Kernel('prod', false), Kernel::class) ?? self::fail('Cannot bind the kernel.');

        $container = $build();
        // The removing passes would inline or remove the private loggers.
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->compile();

        return $container;
    }
}
