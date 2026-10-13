<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Event\DecidedEvent;
use App\Domain\Event\PullRequestLinkedEvent;
use App\Domain\Event\PullRequestUnlinkedEvent;
use App\Domain\Repository\RepositoryName;
use App\Domain\Request\Command\UnlinkPullRequest;
use App\Domain\Request\PullRequestUrl;
use App\Tests\Support\Domain\RequestScenario;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnlinkPullRequestRulesTest extends KernelTestCase
{
    private const string OLD_NAME_URL = 'https://github.com/acme/billing/pull/42';
    private const string NEW_NAME_URL = 'https://github.com/acme/billing-service/pull/42';

    #[TestDox('With one number linked under two names, the unlink removes the URL that it names')]
    public function testExactUrlWinsOverTheNumber(): void
    {
        $agent = RequestScenario::claimingAgent();
        $scenario = RequestScenario::inState('in_progress', linked: false);
        $requestId = $scenario->requestId();
        // The decider links one number once, so the two stored links of a rename before D64 are recorded directly.
        foreach ([self::OLD_NAME_URL, self::NEW_NAME_URL] as $url) {
            $scenario->record(new DecidedEvent(new PullRequestLinkedEvent(PullRequestUrl::fromString($url), [$requestId], null)), $agent);
        }

        [$decided] = $scenario->run(new UnlinkPullRequest($requestId, self::NEW_NAME_URL, RepositoryName::github('acme/billing-service')), $agent);

        self::assertInstanceOf(PullRequestUnlinkedEvent::class, $decided->event);
        self::assertSame(self::NEW_NAME_URL, $decided->event->url->toString());
        self::assertSame([self::OLD_NAME_URL], array_map(static fn (PullRequestUrl $url): string => $url->toString(), $scenario->state()->pullRequestUrls));
    }
}
