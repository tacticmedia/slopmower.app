<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\ReviewClosed;
use App\Domain\Identity\ReviewId;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReviewClosedTest extends KernelTestCase
{
    #[TestDox('Returns the review ID and the close time as details')]
    public function testReturnsItsValuesAsDetails(): void
    {
        $reviewId = ReviewId::generate();
        $closedAt = new \DateTimeImmutable('2026-09-28T16:30:00Z');

        $error = new ReviewClosed($reviewId, $closedAt);

        self::assertSame(['reviewId' => $reviewId, 'closedAt' => $closedAt], $error->details());
    }

    #[TestDox('Names the review in the message')]
    public function testNamesTheReviewInTheMessage(): void
    {
        $error = new ReviewClosed(ReviewId::fromString('01K6A9Z8Y7X6W5V4T3S2R1Q0PN'), new \DateTimeImmutable('2026-09-28T16:30:00Z'));

        self::assertSame('Review 01K6A9Z8Y7X6W5V4T3S2R1Q0PN is closed.', $error->getMessage());
    }
}
