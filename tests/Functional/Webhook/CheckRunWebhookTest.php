<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhook;

use App\Domain\Identity\PullRequestId;
use App\Domain\Identity\TenantId;
use App\Entity\User;
use App\Message\CheckRunJob;
use App\Message\NeutralCheckRunJob;
use App\Messenger\TenantStamp;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Fixtures\TenantFixtures;
use App\Tests\Support\GitHub\GitHubPullRequests;
use App\Tests\Support\GitHub\GitHubResponses;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The `check_run` event, and the neutral check run of a `pull_request` that a tenant does not link. The users sign in
 * with GitHub, whose login names their check.
 */
final class CheckRunWebhookTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use GitHubPullRequests;
    use GitHubRows;
    use GitHubWebhooks;
    use JsonApiAssertions;
    use QueuedMessages;
    use ResetDatabase;

    private const array CHECKS_WRITE = ['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'read', 'checks' => 'write'];

    /** Merged into pull_request.closed.json: the opening of pull request 43. */
    private const array OPENED_43 = ['action' => 'opened', 'number' => 43, 'pull_request' => ['number' => 43, 'state' => 'open', 'merged' => false, 'merged_at' => null, 'closed_at' => null]];

    #[TestDox('check_run rerequested with the external_id of a pull request record queues a job for a new run')]
    public function testRerequestedQueuesTheJob(): void
    {
        $client = static::createClient();
        $user = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($user);
        [, $requestId] = self::claimedGitHubRequest($client, $user, $secret);
        self::linkGitHubPullRequest($client, $secret, $requestId);
        // The read job creates the record of pull request 42.
        self::runQueuedMessages();
        $recordId = Uuid::fromString((string) self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM pull_request'))->toBase32();

        self::deliverFixture($client, 'check_run.rerequested', ['check_run' => ['external_id' => $recordId]]);
        self::runQueuedMessages();

        $jobs = self::sent(CheckRunJob::class);
        self::assertSame([[$user->getTenantId()->toString(), $recordId, true]], array_map(
            static fn (array $sent): array => [$sent[0]->toString(), $sent[1]->pullRequestId->toString(), $sent[1]->newRun],
            $jobs,
        ));
    }

    #[TestDox('check_run rerequested of a run whose record is gone queues the neutral run of the user that its name names')]
    public function testRerequestedWithoutRecordQueuesTheNeutralRun(): void
    {
        $client = static::createClient();
        $user = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($user);
        self::claimedGitHubRequest($client, $user, $secret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($user);

        self::deliverFixture($client, 'check_run.rerequested', ['check_run' => ['name' => 'Slopmower (jan)', 'external_id' => PullRequestId::generate()->toString()]]);
        self::runQueuedMessages();

        self::assertSame([[$user->getTenantId()->toString(), null, 'ec26c3e57ca3a959ca5aad62de7213c562f8c821']], array_map(
            static fn (array $sent): array => [$sent[0]->toString(), $sent[1]->number, $sent[1]->headSha->toString()],
            self::sent(NeutralCheckRunJob::class),
        ));
    }

    #[TestDox('pull_request opened of a number that the tenant does not link creates its neutral check run')]
    public function testUnlinkedPullRequestGetsANeutralRun(): void
    {
        $client = static::createClient();
        $user = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($user);
        self::claimedGitHubRequest($client, $user, $secret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($user);

        self::deliverFixture($client, 'pull_request.closed', self::OPENED_43);
        self::runQueuedMessages();

        self::assertSame([['POST', 'repos/acme/billing/check-runs', [
            'name' => 'Slopmower (jan)',
            'head_sha' => self::headSha(),
            'external_id' => 'tenant:'.$user->getTenantId()->toString(),
            'status' => 'completed',
            'conclusion' => 'neutral',
            'output' => ['title' => 'No linked requests', 'summary' => 'No linked requests'],
        ]]], self::getContainer()->get(GitHubResponses::class)->checkRunCalls());
    }

    #[TestDox('Without the check of every pull request, an unlinked pull request gets no neutral run')]
    public function testUnlinkedPullRequestGetsNoNeutralRunByDefault(): void
    {
        $client = static::createClient();
        $user = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($user);
        self::claimedGitHubRequest($client, $user, $secret, self::CHECKS_WRITE);

        self::deliverFixture($client, 'pull_request.closed', self::OPENED_43);
        self::runQueuedMessages();

        self::assertSame([], self::sent(NeutralCheckRunJob::class));
    }

    #[TestDox('The opening of a pull request on the head of a linked open pull request posts no neutral run')]
    public function testSharedHeadOfALinkedPullRequestGetsNoNeutralRun(): void
    {
        $client = static::createClient();
        $user = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($user);
        [, $requestId] = self::claimedGitHubRequest($client, $user, $secret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($user);
        self::linkGitHubPullRequest($client, $secret, $requestId);
        // The read job records pull request 42, open on the head of pull_request.closed.json.
        self::runQueuedMessages();

        self::deliverFixture($client, 'pull_request.closed', self::OPENED_43);
        self::runQueuedMessages();

        self::assertCount(1, self::sent(NeutralCheckRunJob::class));
        self::assertSame([], self::getContainer()->get(GitHubResponses::class)->checkRunCalls());
    }

    #[TestDox('check_run rerequested of a neutral run queues the neutral run of the tenant that its external_id names, not of its login')]
    public function testRerequestedNeutralRunFollowsItsExternalId(): void
    {
        $client = static::createClient();
        $named = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($named);
        self::claimedGitHubRequest($client, $named, $secret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($named);
        $other = self::signInWithGitHub($client, 2, 'eve');
        [, $otherSecret] = self::storeToken($other);
        self::claimedGitHubRequest($client, $other, $otherSecret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($other);

        self::deliverFixture($client, 'check_run.rerequested', ['check_run' => ['name' => 'Slopmower (eve)', 'external_id' => 'tenant:'.$named->getTenantId()->toString()]]);
        self::runQueuedMessages();

        self::assertSame([$named->getTenantId()->toString()], array_map(static fn (array $sent): string => $sent[0]->toString(), self::sent(NeutralCheckRunJob::class)));
    }

    #[TestDox('Of two tenants of a repository, only the one without a record of the number gets a neutral run')]
    public function testOnlyTheTenantWithoutARecordGetsANeutralRun(): void
    {
        $client = static::createClient();
        $linking = self::signInWithGitHub($client, 1, 'jan');
        [, $secret] = self::storeToken($linking);
        [, $requestId] = self::claimedGitHubRequest($client, $linking, $secret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($linking);
        self::linkGitHubPullRequest($client, $secret, $requestId);
        self::runQueuedMessages();
        $other = self::signInWithGitHub($client, 2, 'eve');
        [, $otherSecret] = self::storeToken($other);
        self::claimedGitHubRequest($client, $other, $otherSecret, self::CHECKS_WRITE);
        self::checkEveryPullRequest($other);

        self::deliverFixture($client, 'pull_request.closed', ['number' => 42, 'pull_request' => ['number' => 42]] + self::OPENED_43);
        self::runQueuedMessages();

        self::assertSame([$other->getTenantId()->toString()], array_map(static fn (array $sent): string => $sent[0]->toString(), self::sent(NeutralCheckRunJob::class)));
    }

    /**
     * As R8 with `enabled: true` leaves the GitHub repository of the user.
     */
    private static function checkEveryPullRequest(User $user): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE repository SET check_every_pull_request = true WHERE tenant_id = ?', [$user->getTenantId()->toRfc4122()]);
    }

    private static function signInWithGitHub(KernelBrowser $client, int $githubUserId, string $login): User
    {
        $user = TenantFixtures::of(self::getContainer())->withGitHubUser($githubUserId)->withLogin($login)->create();
        $client->loginUser($user, 'web');

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<array{TenantId, T}> The queued jobs of the class with the tenants of their TenantStamp
     */
    private static function sent(string $class): array
    {
        $sent = [];

        foreach (self::asyncTransport()->getSent() as $envelope) {
            $job = $envelope->getMessage();

            if ($job instanceof $class) {
                $sent[] = [($envelope->last(TenantStamp::class) ?? self::fail('The job has no TenantStamp.'))->tenantId, $job];
            }
        }

        return $sent;
    }

    private static function headSha(): string
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/github/webhooks/pull_request.closed.json'), true, flags: \JSON_THROW_ON_ERROR);
        $headSha = \is_array($payload) ? $payload['pull_request']['head']['sha'] ?? null : null;

        return \is_string($headSha) ? $headSha : self::fail('pull_request.closed.json has no head SHA.');
    }
}
