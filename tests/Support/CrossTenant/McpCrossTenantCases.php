<?php

declare(strict_types=1);

namespace App\Tests\Support\CrossTenant;

/**
 * One entry per MCP tool, keyed by tool name: argument sets that name the other tenant's data. A `{name}` value takes
 * the ID of the other tenant, and the call expects not_found. A tool without an argument set lists and expects its
 * success result, which holds none of the other tenant's IDs.
 */
final class McpCrossTenantCases
{
    /**
     * @return array<string, list<array<string, string>>>
     */
    public static function all(): array
    {
        return [
            'list_repositories' => [],
            'list_reviews' => [['repository' => '{repositoryId}'], ['repository' => 'billing']],
            'list_requests' => [['review' => '{reviewId}']],
            'get_updates' => [['review' => '{reviewId}']],
            'get_request' => [['id' => '{requestId}']],
        ];
    }
}
