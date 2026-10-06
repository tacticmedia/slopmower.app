<?php

declare(strict_types=1);

namespace App\Tests\Support\CrossTenant;

/**
 * One case per /api/v1 route, keyed by route name. The path is the route path, `status` and `schema` describe its
 * success response. A `{name}` placeholder takes the ID of the other tenant, and the call expects 404 not_found. A
 * case without a placeholder expects its success response, which holds none of the other tenant's IDs.
 * `schema` is null for a response without a JSON body: no body, or bytes. A body value `{name}`, also in a list, takes
 * an ID too. `bearer` cases call with an API token of the caller's tenant, because sessions may not call them. `query`
 * holds the query parameters of the call.
 */
final class CrossTenantCases
{
    /**
     * @return array<string, array{method: string, path: string, body: array<string, mixed>|null, status: int, schema: string|null, bearer?: true, query?: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'api_me' => ['method' => 'GET', 'path' => '/api/v1/me', 'body' => null, 'status' => 200, 'schema' => 'me.schema.json'],
            'api_account_delete' => ['method' => 'DELETE', 'path' => '/api/v1/account', 'body' => null, 'status' => 202, 'schema' => null, 'query' => ['confirm' => 'jan']],
            'api_mercure_authorize' => ['method' => 'POST', 'path' => '/api/v1/mercure/authorize', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/MercureAuthorization'],
            'api_repository_list' => ['method' => 'GET', 'path' => '/api/v1/repositories', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/RepositoryPage'],
            'api_repository_candidates' => ['method' => 'GET', 'path' => '/api/v1/repositories/candidates', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/CandidateList'],
            'api_repository_connect' => ['method' => 'POST', 'path' => '/api/v1/repositories', 'body' => ['source' => 'local', 'directory' => 'billing'], 'status' => 201, 'schema' => 'repository.schema.json'],
            'api_repository_get' => ['method' => 'GET', 'path' => '/api/v1/repositories/{repositoryId}', 'body' => null, 'status' => 200, 'schema' => 'repository.schema.json'],
            'api_repository_delete' => ['method' => 'DELETE', 'path' => '/api/v1/repositories/{repositoryId}', 'body' => null, 'status' => 202, 'schema' => null, 'query' => ['confirm' => 'billing']],
            'api_repository_fetch' => ['method' => 'POST', 'path' => '/api/v1/repositories/{repositoryId}/fetch', 'body' => null, 'status' => 202, 'schema' => 'repository.schema.json'],
            'api_repository_blob' => ['method' => 'GET', 'path' => '/api/v1/repositories/{repositoryId}/blobs/{blobSha}', 'body' => null, 'status' => 200, 'schema' => null],
            'api_review_start' => ['method' => 'POST', 'path' => '/api/v1/repositories/{repositoryId}/reviews', 'body' => ['ref' => 'main'], 'status' => 201, 'schema' => 'review.schema.json'],
            'api_review_list' => ['method' => 'GET', 'path' => '/api/v1/repositories/{repositoryId}/reviews', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/ReviewPage'],
            'api_review_get' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}', 'body' => null, 'status' => 200, 'schema' => 'review.schema.json'],
            'api_review_delete' => ['method' => 'DELETE', 'path' => '/api/v1/reviews/{reviewId}', 'body' => null, 'status' => 204, 'schema' => null],
            'api_review_close' => ['method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/close', 'body' => null, 'status' => 200, 'schema' => 'review.schema.json'],
            'api_review_index' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/index', 'body' => null, 'status' => 200, 'schema' => 'review.schema.json#/$defs/IndexStatus'],
            'api_review_symbols' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/symbols', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/SymbolList'],
            'api_review_declarations' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/declarations', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/DeclarationList', 'query' => ['path' => 'src/Billing.php', 'name' => 'Billing']],
            'api_review_tree' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/tree', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/TreeListing'],
            'api_review_file' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/file', 'body' => null, 'status' => 200, 'schema' => 'file-content.schema.json'],
            'api_review_raw' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/raw', 'body' => null, 'status' => 200, 'schema' => null],
            'api_review_updates' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/updates', 'body' => null, 'status' => 200, 'schema' => 'updates-page.schema.json'],
            'api_request_list' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/requests', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/RequestPage'],
            'api_request_open' => ['method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/requests', 'body' => ['anchor' => ['kind' => 'file', 'path' => 'README.md'], 'body' => 'Use `Money`.'], 'status' => 201, 'schema' => 'request.schema.json'],
            'api_request_get' => ['method' => 'GET', 'path' => '/api/v1/requests/{requestId}', 'body' => null, 'status' => 200, 'schema' => 'request.schema.json'],
            'api_request_comment' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/comments', 'body' => ['body' => 'Keep the old method.'], 'status' => 201, 'schema' => 'request-command-result.schema.json'],
            'api_request_claim' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/claim', 'body' => null, 'status' => 200, 'schema' => 'request-command-result.schema.json', 'bearer' => true],
            'api_request_unlink_commit' => ['method' => 'DELETE', 'path' => '/api/v1/requests/{requestId}/commits/{commitSha}', 'body' => null, 'status' => 200, 'schema' => 'request-command-result.schema.json', 'bearer' => true],
            'api_request_commit_diff' => ['method' => 'GET', 'path' => '/api/v1/requests/{requestId}/commits/{commitSha}', 'body' => null, 'status' => 200, 'schema' => 'commit-diff.schema.json'],
            'api_request_ready' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/ready', 'body' => ['note' => 'Extracted TaxRounding.'], 'status' => 200, 'schema' => 'request-command-result.schema.json', 'bearer' => true],
            'api_commit_link_create' => ['method' => 'POST', 'path' => '/api/v1/commit-links', 'body' => ['commitSha' => '79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091', 'requestIds' => ['{requestId}']], 'status' => 200, 'schema' => 'link-commits-result.schema.json', 'bearer' => true],
            'api_request_release' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/release', 'body' => null, 'status' => 200, 'schema' => 'request-command-result.schema.json'],
            'api_request_wont_do' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/wont-do', 'body' => ['reason' => 'The totals already use integer cents.'], 'status' => 200, 'schema' => 'request-command-result.schema.json'],
            'api_request_verify' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/verify', 'body' => null, 'status' => 200, 'schema' => 'request-command-result.schema.json'],
            'api_request_reopen' => ['method' => 'POST', 'path' => '/api/v1/requests/{requestId}/reopen', 'body' => ['note' => 'Still loses cents.'], 'status' => 200, 'schema' => 'request-command-result.schema.json'],
            'api_draft_list' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/drafts', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/DraftList'],
            'api_draft_create' => ['method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/drafts', 'body' => ['kind' => 'new_request', 'anchor' => ['kind' => 'file', 'path' => 'README.md'], 'body' => 'Use `Money`.'], 'status' => 201, 'schema' => 'draft.schema.json'],
            'api_draft_update' => ['method' => 'PATCH', 'path' => '/api/v1/drafts/{draftId}', 'body' => ['body' => 'Use `Money` for taxes too.'], 'status' => 200, 'schema' => 'draft.schema.json'],
            'api_draft_delete' => ['method' => 'DELETE', 'path' => '/api/v1/drafts/{draftId}', 'body' => null, 'status' => 204, 'schema' => null],
            'api_draft_submit' => ['method' => 'POST', 'path' => '/api/v1/reviews/{reviewId}/drafts/submit', 'body' => null, 'status' => 201, 'schema' => 'batch-submission.schema.json'],
            'api_viewed_file_list' => ['method' => 'GET', 'path' => '/api/v1/reviews/{reviewId}/viewed-files', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/ViewedFiles'],
            'api_viewed_file_mark' => ['method' => 'PUT', 'path' => '/api/v1/reviews/{reviewId}/viewed-files', 'body' => ['path' => 'README.md', 'viewed' => true], 'status' => 200, 'schema' => 'lists.schema.json#/$defs/ViewedFiles'],
            'api_token_list' => ['method' => 'GET', 'path' => '/api/v1/tokens', 'body' => null, 'status' => 200, 'schema' => 'lists.schema.json#/$defs/TokenList'],
            'api_token_create' => ['method' => 'POST', 'path' => '/api/v1/tokens', 'body' => ['name' => 'codex desktop'], 'status' => 201, 'schema' => 'api-token.schema.json#/$defs/ApiTokenWithSecret'],
            'api_token_revoke' => ['method' => 'DELETE', 'path' => '/api/v1/tokens/{tokenId}', 'body' => null, 'status' => 204, 'schema' => null],
        ];
    }
}
