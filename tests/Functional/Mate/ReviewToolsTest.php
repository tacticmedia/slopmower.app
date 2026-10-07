<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mate;

use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\EventStore\CommandFixtures;
use Doctrine\DBAL\Connection;
use Mate\ReviewTools;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReviewToolsTest extends KernelTestCase
{
    use CommandFixtures;
    use ResetDatabase;

    #[TestDox('review-list finds a review by a case-insensitive substring of its repository name')]
    public function testListFindsReviewByRepositorySubstring(): void
    {
        $tenantId = self::enterNewTenant();
        $reviewId = self::startReview(self::newRepository($tenantId, 'billing'));
        self::openRequest($reviewId);
        self::openRequest($reviewId);
        self::startReview(self::newRepository($tenantId, 'shipping'));

        $reviews = self::untrustedList(self::tools()->list(repository: 'ILL'), 'reviews');

        self::assertCount(1, $reviews);
        self::assertSame($reviewId->toString(), $reviews[0]['id']);
        self::assertSame($tenantId->toString(), $reviews[0]['tenantId']);
        self::assertSame('billing', $reviews[0]['repository']);
        self::assertSame(2, $reviews[0]['requestCount']);
    }

    #[TestDox('review-events returns the events of the review in seq order')]
    public function testEventsInSeqOrder(): void
    {
        $reviewId = self::startReview(self::newRepository(self::enterNewTenant()));
        self::openRequest($reviewId);
        self::openRequest($reviewId);

        $events = self::untrustedList(self::tools()->events($reviewId->toString()), 'events');

        self::assertSame([1, 2, 3], array_column($events, 'seq'));
        self::assertSame(['review.started', 'request.opened', 'request.opened'], array_column($events, 'type'));
    }

    #[TestDox('review-events with a request keeps only the events of that request')]
    public function testEventsOfOneRequest(): void
    {
        $reviewId = self::startReview(self::newRepository(self::enterNewTenant()));
        $requestId = self::openRequest($reviewId);
        self::openRequest($reviewId);

        $events = self::untrustedList(self::tools()->events($reviewId->toString(), request: $requestId->toString()), 'events');

        self::assertSame(['request.opened'], array_column($events, 'type'));
        self::assertSame($requestId->toString(), $events[0]['requestId']);
        self::assertSame([$requestId->toString()], $events[0]['requestIds']);
    }

    private static function tools(): ReviewTools
    {
        return new ReviewTools(self::getContainer()->get(Connection::class));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function untrustedList(string $response, string $key): array
    {
        $decoded = ResponseEncoder::decode($response);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['untrusted_data']);
        self::assertIsList($decoded['untrusted_data'][$key]);

        return $decoded['untrusted_data'][$key];
    }
}
