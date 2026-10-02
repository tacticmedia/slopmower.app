<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Review;

use App\Domain\Error\ReviewClosed;
use App\Domain\Review\Command\CloseReview;
use App\Domain\Review\ReviewDecider;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClosedReviewTest extends KernelTestCase
{
    /**
     * Each command runs on a state that allows it while the review is open.
     */
    #[TestDox('Every write by user or agent on a closed review gives review_closed')]
    #[DataProvider('writes')]
    public function testEveryWriteGivesReviewClosed(string $command, string $caller, string $state): void
    {
        $scenario = 'none' === $state ? new RequestScenario() : RequestScenario::inState($state);
        $scenario->closeReview();

        self::assertSame('review_closed', $scenario->outcome($scenario->validCommand($command), RequestScenario::caller($caller)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function writes(): iterable
    {
        yield 'open by user' => ['open', 'user', 'none'];
        yield 'reply by user' => ['reply', 'user', 'in_progress'];
        yield 'reply by agent' => ['reply', 'claiming agent', 'in_progress'];
        yield 'claim by agent' => ['claim', 'another agent', 'open'];
        yield 'repeated claim by the holder' => ['claim', 'claiming agent', 'in_progress'];
        yield 'release by user' => ['release', 'user', 'in_progress'];
        yield 'release by agent' => ['release', 'claiming agent', 'in_progress'];
        yield 'link_commits by agent' => ['link_commits', 'claiming agent', 'in_progress'];
        yield 'unlink_commit by agent' => ['unlink_commit', 'claiming agent', 'in_progress'];
        yield 'mark_ready_for_review by agent' => ['mark_ready_for_review', 'claiming agent', 'in_progress'];
        yield 'mark_wont_do by user' => ['mark_wont_do', 'user', 'in_progress'];
        yield 'mark_wont_do by agent' => ['mark_wont_do', 'claiming agent', 'in_progress'];
        yield 'verify by user' => ['verify', 'user', 'ready_for_review'];
        yield 'reopen by user' => ['reopen', 'user', 'verified'];
    }

    #[TestDox('Closing a closed review gives review_closed')]
    public function testClosingAClosedReviewGivesReviewClosed(): void
    {
        $scenario = new RequestScenario();
        $scenario->closeReview();
        $review = $scenario->review();

        try {
            ReviewDecider::decide(new CloseReview(), $review, RequestScenario::user());
        } catch (ReviewClosed $error) {
            self::assertSame(['reviewId' => $review->id, 'closedAt' => $review->closedAt], $error->details());

            return;
        }

        self::fail('No ReviewClosed was thrown.');
    }
}
