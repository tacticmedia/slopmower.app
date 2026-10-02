<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Domain\Request\Command\MarkWontDo;
use App\Domain\Request\Command\Verify;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/specs/README.md section 4: invalid_input, review_closed, invalid_transition, then commits_not_available.
 */
final class CheckOrderTest extends KernelTestCase
{
    #[TestDox('invalid_input wins over review_closed and invalid_transition')]
    public function testInvalidInputComesFirst(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $scenario->closeReview();

        self::assertSame('invalid_input', $scenario->outcome(new MarkWontDo($scenario->requestId(), 'Too short.'), RequestScenario::otherAgent()));
    }

    #[TestDox('review_closed wins over invalid_transition')]
    public function testReviewClosedComesBeforeInvalidTransition(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $scenario->closeReview();

        self::assertSame('review_closed', $scenario->outcome(new Verify($scenario->requestId()), RequestScenario::otherAgent()));
    }

    #[TestDox('invalid_transition wins over commits_not_available')]
    public function testInvalidTransitionComesBeforeCommitsNotAvailable(): void
    {
        $scenario = RequestScenario::inState('in_progress');
        $scenario->run(new LinkCommits(CommitSha::fromString(RequestScenario::OTHER_COMMIT), [$scenario->requestId()]), RequestScenario::claimingAgent());

        self::assertSame('invalid_transition', $scenario->outcome(new MarkReadyForReview($scenario->requestId(), 'Done.'), RequestScenario::otherAgent()));
    }
}
