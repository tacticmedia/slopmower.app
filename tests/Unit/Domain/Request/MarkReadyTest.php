<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Git\CommitSha;
use App\Domain\Request\Command\LinkCommits;
use App\Domain\Request\Command\MarkReadyForReview;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MarkReadyTest extends KernelTestCase
{
    #[TestDox('Allows zero links')]
    public function testAllowsZeroLinks(): void
    {
        $scenario = RequestScenario::inState('in_progress', linked: false);

        self::assertSame('request.ready_for_review', $scenario->outcome(self::ready($scenario), RequestScenario::claimingAgent()));
    }

    #[TestDox('A pending link gives commits_not_available with the pending SHAs')]
    public function testPendingLinkGivesCommitsNotAvailable(): void
    {
        $scenario = self::withPendingLink();

        try {
            $scenario->decide(self::ready($scenario), RequestScenario::claimingAgent());
        } catch (CommitsNotAvailable $error) {
            self::assertEquals([CommitSha::fromString(RequestScenario::OTHER_COMMIT)], $error->details()['pendingCommits']);

            return;
        }

        self::fail('No CommitsNotAvailable was thrown.');
    }

    #[TestDox('All links available gives ready_for_review')]
    public function testAllLinksAvailableGivesReady(): void
    {
        $scenario = self::withPendingLink();
        $availability = [RequestScenario::commit(RequestScenario::OTHER_COMMIT)];

        self::assertSame('request.ready_for_review', $scenario->outcome(self::ready($scenario), RequestScenario::claimingAgent(), $availability));
    }

    /**
     * One available link and one pending link.
     */
    private static function withPendingLink(): RequestScenario
    {
        $scenario = RequestScenario::inState('in_progress');
        $scenario->run(new LinkCommits(CommitSha::fromString(RequestScenario::OTHER_COMMIT), [$scenario->requestId()]), RequestScenario::claimingAgent());

        return $scenario;
    }

    private static function ready(RequestScenario $scenario): MarkReadyForReview
    {
        return new MarkReadyForReview($scenario->requestId(), 'Extracted TaxRounding.');
    }
}
