<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\BundledSchemas;
use App\Mcp\McpTools;
use App\Mcp\ToolLoader;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ToolHandlersTest extends KernelTestCase
{
    #[TestDox('The handler of each tool takes its input properties in order, with a default exactly for each property that is not required')]
    public function testHandlerParametersMatchTheInputSchema(): void
    {
        foreach (BundledSchemas::TOOLS as $tool) {
            $method = new \ReflectionMethod(McpTools::class, ToolLoader::method($tool['name']));
            $required = $tool['inputSchema']['required'] ?? [];
            self::assertIsArray($required);

            self::assertSame(
                array_map(static fn (string $name): array => [$name, !\in_array($name, $required, true)], array_keys($tool['inputSchema']['properties'])),
                array_map(static fn (\ReflectionParameter $parameter): array => [$parameter->getName(), $parameter->isDefaultValueAvailable()], $method->getParameters()),
                $tool['name'],
            );
        }
    }
}
