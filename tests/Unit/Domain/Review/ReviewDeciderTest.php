<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Review;

use App\Domain\Event\ReviewStartedEvent;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\Command\StartReview;
use App\Domain\Review\ReviewDecider;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReviewDeciderTest extends KernelTestCase
{
    #[TestDox('An agent starts a review')]
    public function testAgentStartsAReview(): void
    {
        $start = new StartReview('main @ 79f8595a4c1e', RefName::fromString('main'), CommitSha::fromString('79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'));

        [$started] = ReviewDecider::decide($start, null, RequestScenario::claimingAgent());

        self::assertInstanceOf(ReviewStartedEvent::class, $started->event);
    }

    #[TestDox('Only a user closes a review')]
    public function testAgentDoesNotCloseAReview(): void
    {
        $this->expectException(\LogicException::class);

        ReviewDecider::decide(new CloseReview(), new RequestScenario()->review(), RequestScenario::claimingAgent());
    }
}
