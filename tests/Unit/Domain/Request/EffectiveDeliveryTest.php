<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Identity\EventId;
use App\Domain\Request\Proposal;
use App\Domain\Request\ProposalState;
use App\Domain\Review\Delivery;
use App\Domain\Review\Workspace;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EffectiveDeliveryTest extends KernelTestCase
{
    #[TestDox('The workspace comes from the review, and the pull request choice of an approval wins over that of the review')]
    public function testApprovalChoiceWinsOverTheReview(): void
    {
        $review = new Delivery(Workspace::Worktree, true);
        $state = RequestScenario::inState('in_progress', linked: false)->state();
        $cases = [
            'no proposal' => [null, true],
            'approved without a choice' => [new Proposal(EventId::generate(), ProposalState::Approved), true],
            'approved with false' => [new Proposal(EventId::generate(), ProposalState::Approved, false), false],
            'pending' => [new Proposal(EventId::generate(), ProposalState::Pending), true],
        ];

        foreach ($cases as $case => [$proposal, $pullRequest]) {
            self::assertEquals(new Delivery(Workspace::Worktree, $pullRequest), $state->with(['proposal' => $proposal])->delivery($review), $case);
        }
    }
}
