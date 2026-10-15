<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Environment\ProdContainer;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProdLogChannelsTest extends KernelTestCase
{
    #[TestDox('In prod, no handler takes the channel mcp')]
    public function testNoHandlerTakesTheMcpChannel(): void
    {
        $container = ProdContainer::build();

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
}
