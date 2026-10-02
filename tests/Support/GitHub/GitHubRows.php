<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use App\Application\ConnectGitHubRepository;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Entity\GitHubCandidate;
use App\Entity\GitHubInstallation;
use App\Entity\Repository;
use App\GitHub\GitHubAccountType;
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
     */
    private static function storeInstallation(TenantId $tenantId, int $installationId = 5551, string $accountLogin = 'acme', ?\DateTimeImmutable $suspendedAt = null): void
    {
        self::storeRow(new GitHubInstallation($tenantId, $installationId, $accountLogin, GitHubAccountType::Organization, $suspendedAt));
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
     * Connects the candidate with R3's service. The clone job stays queued.
     */
    private static function connectGitHub(TenantId $tenantId, int $githubRepositoryId = 812345): Repository
    {
        self::getContainer()->get(TenantContext::class)->set($tenantId);

        return self::getContainer()->get(ConnectGitHubRepository::class)->connect($githubRepositoryId)->repository;
    }

    private static function storeRow(object $row): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($row);
        $entityManager->flush();
        $entityManager->clear();
    }
}
