<?php

declare(strict_types=1);

namespace App\Tests\Support\EventStore;

use App\Application\RequestCommands;
use App\Application\ReviewCommands;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\RequestId;
use App\Domain\Identity\ReviewId;
use App\Domain\Identity\TenantId;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\Command\Open;
use App\Domain\Review\Command\StartReview;
use App\Entity\Repository;
use App\EventStore\CommandExecutor;
use App\Tests\Support\Domain\RequestScenario;
use App\Tests\Support\Persistence\ReviewDataFixtures;
use Doctrine\DBAL\Connection;

/**
 * Writes through the application commands, for KernelTestCase tests that use ResetDatabase.
 */
trait CommandFixtures
{
    use ReviewDataFixtures;

    private static function newRepository(TenantId $tenantId, string $name = 'billing'): RepositoryId
    {
        $repository = Repository::local($tenantId, RepositoryName::local($name), $name, new \DateTimeImmutable(self::TIME));
        self::entityManager()->persist($repository);
        self::entityManager()->flush();
        self::entityManager()->clear();

        return $repository->getId();
    }

    private static function startReview(RepositoryId $repositoryId, string $title = 'main @ 79f8595a4c1e'): ReviewId
    {
        $reviewId = ReviewId::generate();
        $command = new StartReview($title, RefName::fromString('main'), CommitSha::fromString(self::REVIEW_COMMIT));
        self::reviewCommands()->recordStarted($reviewId, $repositoryId, $command, RequestScenario::user());

        return $reviewId;
    }

    private static function openRequest(ReviewId $reviewId, bool $requireApproval = false): RequestId
    {
        $command = new Open(new RequestScenario()->anchor(), 'Use `Money` instead of `float` for totals.', requireApproval: $requireApproval);
        [$opened] = self::requestCommands()->open($reviewId, $command, RequestScenario::user());

        return $opened->requestId ?? self::fail('request.opened has no requestId.');
    }

    private static function reviewCommands(): ReviewCommands
    {
        return self::getContainer()->get(ReviewCommands::class);
    }

    private static function requestCommands(): RequestCommands
    {
        return self::getContainer()->get(RequestCommands::class);
    }

    private static function executor(): CommandExecutor
    {
        return self::getContainer()->get(CommandExecutor::class);
    }

    /**
     * The rows of the tenant in every table that the event store writes.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function storedRows(TenantId $tenantId): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $tenant = [$tenantId->toRfc4122()];

        return [
            'tenant' => $connection->fetchAllAssociative('SELECT * FROM tenant WHERE id = ?', $tenant),
            'review_event' => $connection->fetchAllAssociative('SELECT * FROM review_event WHERE tenant_id = ? ORDER BY seq', $tenant),
            'review' => $connection->fetchAllAssociative('SELECT * FROM review WHERE tenant_id = ? ORDER BY id', $tenant),
            'change_request' => $connection->fetchAllAssociative('SELECT * FROM change_request WHERE tenant_id = ? ORDER BY id', $tenant),
            'comment' => $connection->fetchAllAssociative('SELECT * FROM comment WHERE tenant_id = ? ORDER BY id', $tenant),
            'commit_link' => $connection->fetchAllAssociative('SELECT * FROM commit_link WHERE tenant_id = ? ORDER BY request_id, commit_sha', $tenant),
        ];
    }
}
