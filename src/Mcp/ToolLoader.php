<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;

/**
 * Registers the tools of docs/specs/mcp-tools.json (D99), each with the method of {@see McpTools} that {@see self::method()}
 * names. The SDK casts each argument to the type of its parameter.
 */
final readonly class ToolLoader implements LoaderInterface
{
    public function __construct(private McpTools $tools)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        foreach (BundledSchemas::TOOLS as $tool) {
            $registry->registerTool(Tool::fromArray($tool), [$this->tools, self::method($tool['name'])]);
        }
    }

    /**
     * The camel case of the tool name: `claim_request` is `claimRequest`.
     */
    public static function method(string $tool): string
    {
        return lcfirst(str_replace('_', '', ucwords($tool, '_')));
    }
}
