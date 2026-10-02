<?php

declare(strict_types=1);

namespace App\Tests\Support\CrossTenant;

/**
 * One case per /api/v1 route, keyed by route name. The path is the route path, `status` and `schema` describe its
 * success response. A `{name}` placeholder takes the ID of the other tenant, and the call expects 404 not_found. A
 * case without a placeholder expects its success response, which holds none of the other tenant's IDs.
 * `schema` is null for a response without a JSON body: no body, or bytes.
 */
final class CrossTenantCases
{
    /**
     * @return array<string, array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null}>
     */
    public static function all(): array
    {
        return [
            'api_me' => ['method' => 'GET', 'path' => '/api/v1/me', 'body' => null, 'status' => 200, 'schema' => 'me.schema.json'],
            'api_repository_list' => ['method' => 'GET', 'path' => '/api/v1/repositories', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/RepositoryPage'],
            'api_repository_candidates' => ['method' => 'GET', 'path' => '/api/v1/repositories/candidates', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/CandidateList'],
            'api_repository_connect' => ['method' => 'POST', 'path' => '/api/v1/repositories', 'body' => ['source' => 'local', 'directory' => 'billing'], 'status' => 201, 'schema' => 'repository.schema.json'],
            'api_repository_get' => ['method' => 'GET', 'path' => '/api/v1/repositories/{repositoryId}', 'body' => null, 'status' => 200, 'schema' => 'repository.schema.json'],
            'api_repository_fetch' => ['method' => 'POST', 'path' => '/api/v1/repositories/{repositoryId}/fetch', 'body' => null, 'status' => 202, 'schema' => 'repository.schema.json'],
            'api_repository_blob' => ['method' => 'GET', 'path' => '/api/v1/repositories/{repositoryId}/blobs/{blobSha}', 'body' => null, 'status' => 200, 'schema' => null],
            'api_review_start' => ['method' => 'POST', 'path' => '/api/v1/repositories/{repositoryId}/reviews', 'body' => ['ref' => 'main'], 'status' => 201, 'schema' => 'review.schema.json'],
            'api_review_list' => ['method' => 'GET', 'path' => '/api/v1/repositories/{repositoryId}/reviews', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/ReviewPage'],
            'api_review_get' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}', 'body' => null, 'status' => 200, 'schema' => 'review.schema.json'],
            'api_review_close' => ['method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/close', 'body' => null, 'status' => 200, 'schema' => 'review.schema.json'],
            'api_review_tree' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/tree', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/TreeListing'],
            'api_review_file' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/file', 'body' => null, 'status' => 200, 'schema' => 'file-content.schema.json'],
            'api_review_raw' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/raw', 'body' => null, 'status' => 200, 'schema' => null],
            'api_token_list' => ['method' => 'GET', 'path' => '/api/v1/tokens', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/TokenList'],
            'api_token_create' => ['method' => 'POST', 'path' => '/api/v1/tokens', 'body' => ['name' => 'codex desktop'], 'status' => 201, 'schema' => 'api-token.schema.json#/$defs/ApiTokenWithSecret'],
            'api_token_revoke' => ['method' => 'DELETE', 'path' => '/api/v1/tokens/{tokenId}', 'body' => null, 'status' => 204, 'schema' => null],
        ];
    }
}
