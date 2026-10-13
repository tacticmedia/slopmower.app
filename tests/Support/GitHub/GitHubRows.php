<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Application\FetchRepository;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\GitHubAccountType;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\Entity\Repository;
use App\Repository\GitHubCandidateRepository;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;

/**
 * The GitHub rows that a sign-in records, for tests that use ResetDatabase.
 */
trait GitHubRows
{
    /**
     * Clears the entity manager, so a later read goes through the SQL filter.
     *
     * @param array<string, string> $permissions
     */
    private static function storeInstallation(TenantId $tenantId, int $installationId = 5551, string $accountLogin = 'acme', ?\DateTimeImmutable $suspendedAt = null, array $permissions = ['contents' => 'read', 'metadata' => 'read']): void
    {
        self::storeRow(new GitHubInstallation($tenantId, $installationId, $accountLogin, GitHubAccountType::Organization, $suspendedAt, $permissions));
    }

    /**
     * Clears the entity manager.
     */
    private static function storeCandidate(TenantId $tenantId, int $githubRepositoryId = 812345, string $fullName = 'acme/billing', int $installationId = 5551): void
    {
        self::storeRow(new GitHubCandidate(
            $tenantId,
            $githubRepositoryId,
            $installationId,
            explode('/', $fullName)[0],
            RepositoryName::github($fullName),
            true,
            'main',
            "https://github.com/{$fullName}",
            Clock::get()->now(),
        ));
    }

    /**
     * A `cloning` repository row of the candidate with its clone job queued, as R3 leaves it.
     */
    private static function connectGitHub(TenantId $tenantId, int $githubRepositoryId = 812345): Repository
    {
        $container = self::getContainer();
        $container->get(TenantContext::class)->set($tenantId);
        $candidate = $container->get(GitHubCandidateRepository::class)->findByGitHubId($githubRepositoryId) ?? self::fail("The GitHub repository {$githubRepositoryId} is not a candidate.");
        $repository = Repository::gitHub($tenantId, $candidate->getFullName(), $githubRepositoryId, $candidate->getInstallationId(), $candidate->isPrivate(), Clock::get()->now());
        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($repository);
        $entityManager->flush();
        $container->get(FetchRepository::class)->queueClone($repository);

        return $repository;
    }

    private static function storeRow(object $row): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($row);
        $entityManager->flush();
        $entityManager->clear();
    }
}
