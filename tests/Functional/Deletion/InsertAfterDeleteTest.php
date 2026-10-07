<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Application\ReviewCommands;
use App\Domain\Actor\Actor;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Identity\RepositoryId;
use App\Domain\Identity\ReviewId;
use App\Domain\Review\Command\StartReview;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\DeletionSql;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFilter;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Deletion\TenantRows;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A V13 or R6 delete commits between the read of the review and the insert. The review stays in the identity map of
 * the first request, as when the request read it before the delete committed.
 */
final class InsertAfterDeleteTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use TemporaryDirectories;
    use TenantRows;

    #[TestDox('A draft insert after the review delete gives 404')]
    public function testDraftInsertGivesNotFound(): void
    {
        $client = static::createClient();
        $user = self::signIn($client);
        $review = self::storeReview($user, 'billing');
        self::deleteAfterRead($review->getId(), static fn (DeletionSql $deletion) => $deletion->deleteReview($user->getTenantId(), $review->getId()));

        $response = self::apiRequest($client, 'POST', "/api/v1/reviews/{$review->getId()}/drafts", ['kind' => 'new_request', 'anchor' => null, 'body' => 'Name the rounding mode.']);

        self::assertApiError($response, 404, 'not_found');
        self::assertSame(['review' => 0, 'draft' => 0], self::tenantRowCountsOf($user->getTenantId(), ['review', 'draft']));
    }

    #[TestDox('A viewed-file mark after the review delete gives 404')]
    public function testViewedFileMarkAfterReviewDeleteGivesNotFound(): void
    {
        [$client, $user, $reviewId] = $this->clonedReview();
        self::deleteAfterRead($reviewId, static fn (DeletionSql $deletion) => $deletion->deleteReview($user->getTenantId(), $reviewId));

        $response = self::apiRequest($client, 'PUT', "/api/v1/reviews/{$reviewId}/viewed-files", ['path' => 'README.md', 'viewed' => true]);

        self::assertApiError($response, 404, 'not_found');
        self::assertSame(['review' => 0, 'viewed_file' => 0], self::tenantRowCountsOf($user->getTenantId(), ['review', 'viewed_file']));
    }

    #[TestDox('A viewed-file mark after the repository delete gives 404')]
    public function testViewedFileMarkAfterRepositoryDeleteGivesNotFound(): void
    {
        [$client, $user, $reviewId, $repositoryId] = $this->clonedReview();
        self::deleteAfterRead($reviewId, static fn (DeletionSql $deletion) => $deletion->deleteRepository($user->getTenantId(), $repositoryId));

        $response = self::apiRequest($client, 'PUT', "/api/v1/reviews/{$reviewId}/viewed-files", ['path' => 'README.md', 'viewed' => true]);

        self::assertApiError($response, 404, 'not_found');
        self::assertSame(['repository' => 0, 'viewed_file' => 0], self::tenantRowCountsOf($user->getTenantId(), ['repository', 'viewed_file']));
    }

    /**
     * A review of the HEAD commit of a cloned local repository, without a request.
     *
     * @return array{KernelBrowser, User, ReviewId, RepositoryId}
     */
    private function clonedReview(): array
    {
        $this->useTemporaryLocalSources();
        $commit = $this->newSource()->revParse('HEAD');
        $client = static::createClient();
        $user = self::signIn($client);
        self::getContainer()->get(TenantContext::class)->set($user->getTenantId());
        $repository = self::connect();
        self::runQueuedMessages();
        $reviewId = ReviewId::generate();
        self::getContainer()->get(ReviewCommands::class)
            ->recordStarted($reviewId, $repository->getId(), new StartReview('Race', RefName::fromString('main'), CommitSha::fromString($commit)), Actor::user($user->getId()));

        return [$client, $user, $reviewId, $repository->getId()];
    }

    /**
     * Loads the review into the identity map, then commits the delete.
     *
     * @param \Closure(DeletionSql): void $delete
     */
    private static function deleteAfterRead(ReviewId $reviewId, \Closure $delete): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::getContainer()->get(TenantFilter::class)->suspend(static fn (): ?Review => $entityManager->find(Review::class, $reviewId))
            ?? self::fail('The review does not exist.');
        $delete(self::getContainer()->get(DeletionSql::class));
    }
}
