<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Domain\Identity\RepositoryId;
use App\Entity\User;
use App\Git\GitRunner;
use App\Git\PinRefs;
use App\Git\TenantDataPaths;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Repositories and reviews through the API, for WebTestCase tests that use ApiFixtures, JsonApiAssertions,
 * LocalSources, and QueuedMessages.
 */
trait ReviewApiFixtures
{
    /**
     * Connects the local source with R3 and runs the clone job.
     *
     * @return string The repository ID
     */
    private static function connectAndClone(KernelBrowser $client, string $directory = 'billing'): string
    {
        $repository = self::assertApiResponse(self::apiRequest($client, 'POST', '/api/v1/repositories', ['source' => 'local', 'directory' => $directory]), 201, 'repository.schema.json');
        self::runQueuedMessages();

        return (string) $repository['id'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> The Review
     */
    private static function startReviewThroughApi(KernelBrowser $client, string $repositoryId, array $body = ['ref' => 'main']): array
    {
        return self::assertApiResponse(self::apiRequest($client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", $body), 201, 'review.schema.json');
    }

    /**
     * @return array<string, string> Commit SHA by pin ref name
     */
    private static function pins(User $user, string $repositoryId): array
    {
        $gitDir = self::getContainer()->get(TenantDataPaths::class)->clonePath($user->getTenantId(), RepositoryId::fromString($repositoryId));

        return array_map(strval(...), new PinRefs(new GitRunner(new NullLogger()))->list($gitDir));
    }
}
