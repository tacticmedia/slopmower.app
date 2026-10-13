<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\ReviewId;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\Command\Open;
use App\Entity\Repository;
use App\Entity\Review;
use App\Entity\User;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;

/**
 * Pull request links on a review of the GitHub repository acme/billing, for WebTestCase tests that use ApiFixtures,
 * AgentApiFixtures, GitHubRows, and JsonApiAssertions.
 */
trait GitHubPullRequests
{
    /**
     * A general request of a new review of acme/billing, the GitHub repository 812345 of installation 5551, without a
     * clone. It is opened through the application and claimed through REST by the agent of $secret. Clears the entity
     * manager.
     *
     * @param array<string, string> $permissions The accepted permissions of installation 5551
     *
     * @return array{ReviewId, string} The review and the request ID
     */
    private static function claimedGitHubRequest(KernelBrowser $client, User $user, #[\SensitiveParameter] string $secret, array $permissions = ['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'read']): array
    {
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $now = Clock::get()->now();
        self::storeInstallation($user->getTenantId(), permissions: $permissions);
        $repository = Repository::gitHub($user->getTenantId(), RepositoryName::github('acme/billing'), 812345, 5551, true, $now);
        $review = new Review(ReviewId::generate(), $repository, 'main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'), Actor::user($user->getId()), $now, 1);
        $entityManager->persist($repository);
        $entityManager->persist($review);
        $entityManager->flush();
        $container->get(TenantContext::class)->set($user->getTenantId());
        [$opened] = $container->get(RequestCommands::class)->open($review->getId(), new Open(null, 'Use `Money` instead of `float` for totals.'), Actor::user($user->getId()));
        $entityManager->clear();
        $requestId = (string) ($opened->requestId ?? self::fail('request.opened has no requestId.'));
        self::agentRequest($client, $secret, 'POST', "/api/v1/requests/{$requestId}/claim");

        return [$review->getId(), $requestId];
    }

    /**
     * @return array<string, mixed> The LinkPullRequestResult
     */
    private static function linkGitHubPullRequest(KernelBrowser $client, #[\SensitiveParameter] string $secret, string $requestId, string $url = 'https://github.com/acme/billing/pull/42'): array
    {
        return self::agentRequest($client, $secret, 'POST', '/api/v1/pull-request-links', ['url' => $url, 'requestIds' => [$requestId]], schema: 'link-pull-request-result.schema.json');
    }
}
