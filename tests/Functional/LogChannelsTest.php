<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Environment\EnvironmentContainer;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class LogChannelsTest extends KernelTestCase
{
    #[TestDox('In prod, no handler takes the channel mcp')]
    public function testNoHandlerTakesTheMcpChannel(): void
    {
        $container = EnvironmentContainer::build('prod');

        self::assertContains('monolog.handler.main', self::handlerIds($container, 'monolog.logger'));
        self::assertSame([], self::handlerIds($container, 'monolog.logger.mcp'));
    }

    #[TestDox('In $environment, the idle poll of the worker logs on the channel messenger_idle, which no handler takes')]
    #[TestWith(['dev'])]
    #[TestWith(['prod'])]
    public function testNoHandlerTakesTheIdleWorkerChannel(string $environment): void
    {
        $container = EnvironmentContainer::build($environment);

        self::assertSame('monolog.logger.messenger_idle', (string) $container->getDefinition('messenger.transport.doctrine.pg_notify_on_idle_listener')->getArgument(0));
        self::assertContains('monolog.handler.main', self::handlerIds($container, 'monolog.logger'));
        self::assertSame([], self::handlerIds($container, 'monolog.logger.messenger_idle'));
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
}
