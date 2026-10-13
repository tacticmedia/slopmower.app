<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Support\Api\AgentApiFixtures;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\GitHubPullRequests;
use App\Tests\Support\GitHub\GitHubRows;
use App\Tests\Support\GitHub\GitHubWebhooks;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Q17 and Q19. The agent claimed two requests of a review of a local repository.
 */
final class PullRequestLinkTest extends WebTestCase
{
    use AgentApiFixtures;
    use ApiFixtures;
    use EnvironmentVariables;
    use GitHubPullRequests;
    use GitHubRows;
    use GitHubWebhooks;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private const string URL = 'https://github.com/acme/billing/pull/42';

    /** The URL after the rename of tests/Fixtures/github/webhooks/repository.renamed.json. */
    private const string RENAMED_URL = 'https://github.com/acme/billing-service/pull/42';

    private const string RESULT = 'link-pull-request-result.schema.json';

    private KernelBrowser $client;

    private User $user;

    private string $secret;

    private string $reviewId;

    private string $first;

    private string $second;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
        $this->first = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $this->second = (string) self::openRequest($this->client, $this->reviewId)['id'];
        [, $this->secret] = self::storeToken($this->user);
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->first}/claim");
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->second}/claim");
    }

    #[TestDox('One link names two requests, and both threads and both pull request lists show it')]
    public function testOneLinkNamesTwoRequests(): void
    {
        $result = $this->link([$this->first, $this->second], 'One pull request for both requests.');

        self::assertSame(self::URL, $result['url']);
        self::assertSame([$this->first, $this->second], array_column($result['requests'], 'id'));
        self::assertSame([['pull_request.linked', null, [$this->first, $this->second], self::URL, null]], array_map(
            static fn (array $event): array => [$event['type'], $event['requestId'], $event['requestIds'], $event['pullRequestUrl'], $event['statusAfter']],
            $result['events'],
        ));

        foreach ([$this->first, $this->second] as $requestId) {
            $request = self::readRequest($this->client, $requestId);
            $entry = array_last($request['thread']);

            self::assertSame([self::URL], $request['pullRequestUrls'], $requestId);
            self::assertSame(['pull_request.linked', self::URL, 'One pull request for both requests.', 'in_progress'], [$entry['type'], $entry['pullRequestUrl'], $entry['body'], $entry['statusAfter']], $requestId);
        }
    }

    #[TestDox('A repeated call writes no event')]
    public function testRepeatedCallWritesNoEvent(): void
    {
        $this->link([$this->first]);

        $again = $this->link([$this->first]);

        self::assertSame([], $again['events']);
        self::assertSame([self::URL], self::readRequest($this->client, $this->first)['pullRequestUrls']);
    }

    #[TestDox('A pull request of another GitHub repository gives 422 on a GitHub repository')]
    public function testForeignGitHubUrlGivesInvalidInput(): void
    {
        [, $requestId] = self::claimedGitHubRequest($this->client, $this->user, $this->secret);

        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/pull-request-links', ['url' => 'https://github.com/acme/ledger/pull/42', 'requestIds' => [$requestId]]), 422, 'invalid_input');

        self::assertSame(['url'], array_column($error['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('On a GitHub repository, a URL in another letter case names the linked pull request and writes no event')]
    public function testGitHubUrlInAnotherCaseIsAlreadyLinked(): void
    {
        [, $requestId] = self::claimedGitHubRequest($this->client, $this->user, $this->secret);
        $this->link([$requestId]);

        $again = self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/pull-request-links', ['url' => 'https://github.com/Acme/Billing/pull/42', 'requestIds' => [$requestId]], schema: self::RESULT);

        self::assertSame([self::URL, []], [$again['url'], $again['events']]);
    }

    #[TestDox('A ready_for_review request accepts a link')]
    public function testReadyForReviewRequestAcceptsALink(): void
    {
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->first}/ready", ['note' => 'Extracted TaxRounding.']);

        $result = $this->link([$this->first]);

        self::assertSame(['ready_for_review', 'user'], [$result['requests'][0]['status'], $result['requests'][0]['awaiting']]);
        self::assertCount(1, $result['events']);
    }

    #[TestDox('An open request refuses a link and nothing is written')]
    public function testOpenRequestRefusesALink(): void
    {
        $open = (string) self::openRequest($this->client, $this->reviewId)['id'];

        $error = self::assertApiError(self::bearerRequest($this->client, $this->secret, 'POST', '/api/v1/pull-request-links', ['url' => self::URL, 'requestIds' => [$this->first, $open]]), 409, 'invalid_transition');

        self::assertSame(['requestId' => $open, 'action' => 'link_pull_request', 'status' => 'open'], array_intersect_key($error['details'] ?? [], array_flip(['requestId', 'action', 'status'])));
        self::assertSame([], self::readRequest($this->client, $this->first)['pullRequestUrls']);
    }

    #[TestDox('Unlink removes the URL from pullRequestUrls and adds the thread entry')]
    public function testUnlinkRemovesTheUrl(): void
    {
        $this->link([$this->first, $this->second]);

        $result = $this->unlink($this->first, self::URL);

        self::assertSame([['pull_request.unlinked', $this->first, [$this->first], self::URL, 'in_progress']], array_map(
            static fn (array $event): array => [$event['type'], $event['requestId'], $event['requestIds'], $event['pullRequestUrl'], $event['statusAfter']],
            $result['events'],
        ));
        $request = self::readRequest($this->client, $this->first);
        $entry = array_last($request['thread']);
        self::assertSame([], $request['pullRequestUrls']);
        self::assertSame(['pull_request.unlinked', self::URL], [$entry['type'], $entry['pullRequestUrl']]);
        self::assertSame([self::URL], self::readRequest($this->client, $this->second)['pullRequestUrls']);
    }

    #[TestDox('Unlinking a URL that is not linked gives 422')]
    public function testUnlinkOfAnUnknownUrlGivesInvalidInput(): void
    {
        $this->link([$this->first]);

        $error = self::assertApiError($this->unlinkRequest($this->first, 'https://github.com/acme/billing/pull/43'), 422, 'invalid_input');

        self::assertSame([['field' => 'url', 'message' => 'Is not linked to the request.']], $error['details']['violations'] ?? null);
    }

    #[TestDox('On a GitHub repository, a URL in another letter case unlinks the linked pull request')]
    public function testGitHubUrlInAnotherCaseUnlinks(): void
    {
        [, $requestId] = self::claimedGitHubRequest($this->client, $this->user, $this->secret);
        $this->link([$requestId]);

        $result = $this->unlink($requestId, 'https://github.com/Acme/Billing/pull/42');

        self::assertSame([self::URL], array_column($result['events'], 'pullRequestUrl'));
        self::assertSame([], self::readRequest($this->client, $requestId)['pullRequestUrls']);
    }

    #[TestDox('After a rename of the GitHub repository, unlink with the old URL removes the link')]
    public function testOldUrlUnlinksAfterARename(): void
    {
        [, $requestId] = self::claimedGitHubRequest($this->client, $this->user, $this->secret);
        $this->link([$requestId]);
        self::deliverFixture($this->client, 'repository.renamed');
        self::runQueuedMessages();

        $result = $this->unlink($requestId, self::URL);

        self::assertSame([self::URL], array_column($result['events'], 'pullRequestUrl'));
        self::assertSame([], self::readRequest($this->client, $requestId)['pullRequestUrls']);
    }

    #[TestDox('After a rename of the GitHub repository, the URL with the new name names the pull request linked under the old name')]
    public function testNewUrlIsAlreadyLinkedAfterARename(): void
    {
        [, $requestId] = self::claimedGitHubRequest($this->client, $this->user, $this->secret);
        $this->link([$requestId]);
        self::deliverFixture($this->client, 'repository.renamed');
        self::runQueuedMessages();

        $again = self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/pull-request-links', ['url' => self::RENAMED_URL, 'requestIds' => [$requestId]], schema: self::RESULT);

        self::assertSame([self::RENAMED_URL, []], [$again['url'], $again['events']]);
        self::assertSame([self::URL], self::readRequest($this->client, $requestId)['pullRequestUrls']);
    }

    #[TestDox('An open request refuses an unlink and keeps the link')]
    public function testOpenRequestRefusesAnUnlink(): void
    {
        $this->link([$this->first]);
        self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$this->first}/release");

        $error = self::assertApiError($this->unlinkRequest($this->first, self::URL), 409, 'invalid_transition');

        self::assertSame(['requestId' => $this->first, 'action' => 'unlink_pull_request', 'status' => 'open'], array_intersect_key($error['details'] ?? [], array_flip(['requestId', 'action', 'status'])));
        self::assertSame([self::URL], self::readRequest($this->client, $this->first)['pullRequestUrls']);
    }

    /**
     * @return array<string, mixed> The RequestCommandResult
     */
    private function unlink(string $requestId, string $url): array
    {
        return self::agentRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/unlink-pull-request", ['url' => $url]);
    }

    private function unlinkRequest(string $requestId, string $url): Response
    {
        return self::bearerRequest($this->client, $this->secret, 'POST', "/api/v1/requests/{$requestId}/unlink-pull-request", ['url' => $url]);
    }

    /**
     * @param list<string> $requestIds
     *
     * @return array<string, mixed> The LinkPullRequestResult
     */
    private function link(array $requestIds, ?string $note = null): array
    {
        return self::agentRequest($this->client, $this->secret, 'POST', '/api/v1/pull-request-links', ['url' => self::URL, 'requestIds' => $requestIds, 'note' => $note], schema: self::RESULT);
    }
}
