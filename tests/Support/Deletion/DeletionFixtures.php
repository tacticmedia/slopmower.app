<?php

declare(strict_types=1);

namespace App\Tests\Support\Deletion;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Reviews with a row in every table that a deletion empties, for WebTestCase tests that use AgentApiFixtures,
 * DraftApiFixtures, RequestApiFixtures, ReviewApiFixtures, and their traits.
 */
trait DeletionFixtures
{
    use TenantRows;

    /**
     * A review of main with a request, its comment, its link of $commit, its location cache entry, a draft of the
     * user and one of $secondUser, and a viewed file. The agent of $secret claims the request. Runs each queued job
     * before the next request reboots the kernel and drops the queue, so the index and the location cache exist.
     *
     * @return array{reviewId: string, requestId: string, draftIds: list<string>}
     */
    private static function populatedReview(KernelBrowser $client, User $user, User $secondUser, string $repositoryId, #[\SensitiveParameter] string $secret, string $commit): array
    {
        $reviewId = (string) self::startReviewThroughApi($client, $repositoryId)['id'];
        self::runQueuedMessages();
        $requestId = (string) self::openRequest($client, $reviewId)['id'];
        self::runQueuedMessages();
        self::requestCommand($client, $requestId, 'comments', ['body' => 'Keep the rounding mode.']);
        self::agentRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim");
        self::agentRequest($client, $secret, 'POST', '/api/v1/commit-links', ['commitSha' => $commit, 'requestIds' => [$requestId]], schema: 'link-commits-result.schema.json');
        self::assertApiResponse(self::apiRequest($client, 'PUT', "/api/v1/reviews/{$reviewId}/viewed-files", ['path' => 'README.md', 'viewed' => true]), 200, 'lists.schema.json#/$defs/ViewedFiles');
        $draftIds = [(string) self::createDraft($client, $reviewId)['id']];
        $client->loginUser($secondUser, 'web');
        $draftIds[] = (string) self::createDraft($client, $reviewId)['id'];
        $client->loginUser($user, 'web');
        self::runQueuedMessages();

        return ['reviewId' => $reviewId, 'requestId' => $requestId, 'draftIds' => $draftIds];
    }
}
