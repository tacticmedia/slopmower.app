<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Event\RepositoryFetched;
use App\Domain\Actor\Actor;
use App\Domain\Event\EventType;
use App\Domain\Identity\ReviewId;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\EventStore\EventLog;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StartReviewTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use EventLog;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private GitRepositoryBuilder $source;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->source = $this->newSource();
        $this->client = static::createClient();
        // One container for all requests, so a listener of the test sees the fetches of a request.
        $this->client->disableReboot();
        $this->user = self::signIn($this->client);
    }

    #[TestDox('Starts a review at a branch, a tag, and a SHA')]
    public function testStartsAtBranchTagAndSha(): void
    {
        $tagged = $this->source->revParse('HEAD');
        $this->source->git('tag', '--annotate', '--message=Release', 'v1.0');
        $head = $this->source->commit(['later.txt' => "Later\n"]);
        $repositoryId = self::connectAndClone($this->client);

        $response = self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => 'main', 'title' => null]);
        $atBranch = self::assertApiResponse($response, 201, 'review.schema.json');
        $atTag = self::startReviewThroughApi($this->client, $repositoryId, ['ref' => 'v1.0']);
        $atSha = self::startReviewThroughApi($this->client, $repositoryId, ['ref' => $tagged]);
        $atShortSha = self::startReviewThroughApi($this->client, $repositoryId, ['ref' => substr($tagged, 0, 10)]);

        self::assertSame("/api/v1/reviews/{$atBranch['id']}", $response->headers->get('Location'));
        self::assertSame(['main', $head, 'main @ '.substr($head, 0, 12), 'open'], [$atBranch['ref'], $atBranch['commitSha'], $atBranch['title'], $atBranch['state']]);
        self::assertSame(['v1.0', $tagged, 'v1.0 @ '.substr($tagged, 0, 12)], [$atTag['ref'], $atTag['commitSha'], $atTag['title']]);
        self::assertSame([$tagged, $tagged, substr($tagged, 0, 12)], [$atSha['ref'], $atSha['commitSha'], $atSha['title']]);
        self::assertSame([substr($tagged, 0, 10), $tagged, substr($tagged, 0, 10).' @ '.substr($tagged, 0, 12)], [$atShortSha['ref'], $atShortSha['commitSha'], $atShortSha['title']]);
    }

    #[TestDox('The review pin points at the review commit')]
    public function testPinPointsAtReviewCommit(): void
    {
        $repositoryId = self::connectAndClone($this->client);

        $review = self::startReviewThroughApi($this->client, $repositoryId);

        self::assertSame(["refs/slopmower/reviews/{$review['id']}" => $this->source->revParse('main')], self::pins($this->user, $repositoryId));
    }

    #[TestDox('The review.started event has actor user and the default title')]
    public function testStartedEventHasUserActorAndDefaultTitle(): void
    {
        $repositoryId = self::connectAndClone($this->client);
        $head = $this->source->revParse('main');

        $review = self::startReviewThroughApi($this->client, $repositoryId, []);

        [$started] = self::eventLog($this->user->getTenantId(), ReviewId::fromString((string) $review['id']));
        self::assertSame(EventType::ReviewStarted, $started->type);
        self::assertEquals(Actor::user($this->user->getId()), $started->actor);
        self::assertSame(['title' => 'main @ '.substr($head, 0, 12), 'ref' => 'main', 'commitSha' => $head], $started->event->toPayload());
    }

    #[TestDox('A 255-character ref gives a default title of 200 characters')]
    public function testLongRefGivesTitleOf200Characters(): void
    {
        $branch = 'feature/'.str_repeat('a', 120).'/'.str_repeat('b', 126);
        $this->source->git('branch', $branch);
        $repositoryId = self::connectAndClone($this->client);

        $review = self::startReviewThroughApi($this->client, $repositoryId, ['ref' => $branch]);

        self::assertSame(255, strlen($branch));
        self::assertSame(substr($branch, 0, 185).' @ '.substr($this->source->revParse('main'), 0, 12), $review['title']);
        self::assertSame(200, mb_strlen((string) $review['title']));
    }

    #[TestDox('A full SHA that is already in the clone starts without a fetch')]
    public function testFullShaInCloneStartsWithoutFetch(): void
    {
        $repositoryId = self::connectAndClone($this->client);
        $fetches = 0;
        self::getContainer()->get('event_dispatcher')->addListener(RepositoryFetched::class, static function () use (&$fetches): void {
            ++$fetches;
        });

        self::startReviewThroughApi($this->client, $repositoryId, ['ref' => $this->source->revParse('main')]);
        $fetchesForSha = $fetches;
        self::startReviewThroughApi($this->client, $repositoryId, ['ref' => 'main']);

        self::assertSame([0, 1], [$fetchesForSha, $fetches]);
    }

    #[TestDox('An unknown ref gives 422 commit_not_found')]
    public function testUnknownRefGivesCommitNotFound(): void
    {
        $repositoryId = self::connectAndClone($this->client);

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => 'no-such-branch']), 422, 'commit_not_found');

        self::assertSame(['commit' => 'no-such-branch'], $error['details']);
        self::assertSame([], self::pins($this->user, $repositoryId));
    }

    #[TestDox('An option-like ref or an ambiguous prefix gives 422 invalid_input')]
    public function testOptionLikeRefOrAmbiguousPrefixGivesInvalidInput(): void
    {
        $this->source->branchCommitsWithAmbiguousPrefix();
        $repositoryId = self::connectAndClone($this->client);

        foreach (['--upload-pack=touch /tmp/pwned', GitRepositoryBuilder::AMBIGUOUS_PREFIX] as $ref) {
            $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/repositories/{$repositoryId}/reviews", ['ref' => $ref]), 422, 'invalid_input');
            self::assertSame(['ref'], array_column($error['details']['violations'] ?? [], 'field'), $ref);
        }
    }
}
