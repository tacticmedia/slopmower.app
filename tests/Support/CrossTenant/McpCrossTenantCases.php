<?php

declare(strict_types=1);

namespace App\Tests\Support\CrossTenant;

/**
 * One entry per MCP tool, keyed by tool name: argument sets that name the other tenant's data. A `{name}` value, also
 * in a list, takes the ID or the key of the other tenant, and the call expects not_found. SM-1 is an issue of the token's
 * tenant. A tool without an argument set lists
 * and expects its success result, which holds none of the other tenant's IDs.
 */
final class McpCrossTenantCases
{
    private const string COMMIT_SHA = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const string PLAN_REVISION = '01K7F3C5D6E7F8G9H0J1K2M3N4';

    /**
     * @return array<string, list<array<string, string|bool|list<string>>>>
     */
    public static function all(): array
    {
        return [
            'list_repositories' => [],
            'list_reviews' => [['repository' => '{repositoryId}'], ['repository' => 'billing']],
            'list_requests' => [['review' => '{reviewId}']],
            'get_updates' => [['review' => '{reviewId}']],
            'get_request' => [['id' => '{requestId}']],
            'claim_request' => [['id' => '{requestId}']],
            'release_request' => [['id' => '{requestId}']],
            'reply_to_request' => [['id' => '{requestId}', 'body' => 'Done.', 'question' => true]],
            'link_commits' => [['commitSha' => self::COMMIT_SHA, 'requestIds' => ['{requestId}']]],
            'unlink_commit' => [['commitSha' => self::COMMIT_SHA, 'requestId' => '{requestId}']],
            'link_pull_request' => [['url' => 'https://github.com/acme/billing/pull/42', 'requestIds' => ['{requestId}']]],
            'unlink_pull_request' => [['url' => 'https://github.com/acme/billing/pull/42', 'requestId' => '{requestId}']],
            'mark_ready_for_review' => [['id' => '{requestId}', 'note' => 'Done.']],
            'mark_wont_do' => [['id' => '{requestId}', 'reason' => 'The totals already use integer cents.']],
            'propose_solution' => [['id' => '{requestId}', 'plan' => 'Extract `TaxRounding`.']],
            'start_review' => [['repository' => '{repositoryId}'], ['repository' => 'billing']],
            'suggest_request' => [['review' => '{reviewId}', 'title' => 'Use Money', 'body' => 'Use `Money`.']],
            'set_request_title' => [['id' => '{requestId}', 'title' => 'Use Money']],
            'list_projects' => [],
            'list_issues' => [['project' => '{projectKey}']],
            'get_issue' => [['issue' => '{issueKey}']],
            'create_issue' => [['project' => '{projectKey}', 'title' => 'Use Money']],
            'update_issue' => [['issue' => '{issueKey}', 'title' => 'Use Money']],
            'set_issue_status' => [['issue' => '{issueKey}', 'status' => 'backlog']],
            'comment_on_issue' => [['issue' => '{issueKey}', 'body' => 'Done.']],
            'link_issues' => [['issue' => '{issueKey}', 'target' => '{issueKey}', 'note' => 'The same change.'], ['issue' => 'SM-1', 'target' => '{issueKey}', 'note' => 'The same change.']],
            'approve_plan' => [['issue' => '{issueKey}', 'planRevision' => self::PLAN_REVISION, 'reviewer' => 'plan-reviewer', 'reviewNote' => 'The plan covers the rounding and its test.']],
        ];
    }
}
