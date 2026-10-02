<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Domain\Identity\TenantId;
use App\Entity\Repository;
use App\Repository\RepositoryRepository;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Fixtures\TenantFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

/**
 * Tenants with a cloned GitHub repository, and their stored rows read across tenants. For tests that use GitHubRows,
 * QueuedMessages, and LocalGitHub with the source acme/billing.
 */
trait ClonedGitHubRepositories
{
    /**
     * A tenant of the GitHub user with installation 5551 and the candidate acme/billing, connected and cloned.
     */
    private static function clonedGitHubRepository(int $githubUserId): Repository
    {
        $tenantId = TenantFixtures::of(self::getContainer())->withGitHubUser($githubUserId)->create()->getTenantId();
        self::storeInstallation($tenantId);
        self::storeCandidate($tenantId);
        $repository = self::connectGitHub($tenantId);
        self::runQueuedMessages();

        return $repository;
    }

    private static function storedRepository(Repository $repository): Repository
    {
        return self::getContainer()->get(TenantFilter::class)->suspend(
            static fn (): ?Repository => self::getContainer()->get(RepositoryRepository::class)->findFresh($repository->getId()),
        ) ?? self::fail("The repository {$repository->getId()} does not exist.");
    }

    /**
     * @return list<int>
     */
    private static function storedCandidateIds(TenantId $tenantId): array
    {
        return array_map(intval(...), self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT github_repository_id FROM github_candidate WHERE tenant_id = ? ORDER BY github_repository_id',
            [Ulid::fromString($tenantId->toString())->toRfc4122()],
        ));
    }

    private static function countTenantRows(string $table, TenantId $tenantId): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT count(*) FROM {$table} WHERE tenant_id = ?", [Ulid::fromString($tenantId->toString())->toRfc4122()]);
    }
}
