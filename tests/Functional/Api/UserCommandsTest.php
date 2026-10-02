<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\RequestCommands;
use App\Domain\Actor\Actor;
use App\Domain\Identity\RequestId;
use App\Domain\Request\Command\Claim;
use App\Entity\User;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserCommandsTest extends WebTestCase
{
    use ApiFixtures;
    use EnvironmentVariables;
    use JsonApiAssertions;
    use LocalSources;
    use QueuedMessages;
    use RequestApiFixtures;
    use ResetDatabase;
    use ReviewApiFixtures;
    use TemporaryDirectories;

    private KernelBrowser $client;

    private User $user;

    private string $reviewId;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource()->commit(self::codeFiles());
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $this->reviewId = self::startCodeReview($this->client);
    }

    #[TestDox('Verify on an open request gives 409 invalid_transition with details')]
    public function testVerifyOnOpenRequestGivesInvalidTransition(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];

        $error = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$requestId}/verify"), 409, 'invalid_transition');

        self::assertSame(['requestId' => $requestId, 'action' => 'verify', 'status' => 'open', 'claimedBy' => null, 'allowedActions' => ['reply', 'mark_wont_do']], $error['details']);
    }

    #[TestDox('Reopen needs a note')]
    public function testReopenNeedsNote(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];
        self::requestCommand($this->client, $requestId, 'wont-do', ['reason' => self::WONT_DO_REASON]);

        $withoutNote = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$requestId}/reopen", []), 422, 'invalid_input');
        $blankNote = self::assertApiError(self::apiRequest($this->client, 'POST', "/api/v1/requests/{$requestId}/reopen", ['note' => " \n "]), 422, 'invalid_input');
        $reopened = self::requestCommand($this->client, $requestId, 'reopen', ['note' => 'Integer cents still round twice.']);

        self::assertSame(['note', 'note'], [$withoutNote['details']['violations'][0]['field'] ?? null, $blankNote['details']['violations'][0]['field'] ?? null]);
        self::assertSame(['reopened', 'agent'], [$reopened['request']['status'], $reopened['request']['awaiting']]);
        self::assertSame(['request.reopened', 'Integer cents still round twice.', 'reopened'], [$reopened['events'][0]['type'], $reopened['events'][0]['body'], $reopened['events'][0]['statusAfter']]);
    }

    #[TestDox('A user wont_do closes the request')]
    public function testUserWontDoClosesRequest(): void
    {
        $requestId = (string) self::openRequest($this->client, $this->reviewId)['id'];

        $result = self::requestCommand($this->client, $requestId, 'wont-do', ['reason' => self::WONT_DO_REASON]);

        $expected = ['status' => 'wont_do', 'awaiting' => 'none', 'resolution' => 'wont_do', 'closed' => true];
        self::assertSame($expected, array_intersect_key($result['request'], $expected));
        self::assertSame(['reply', 'reopen'], $result['allowedActions']);
        self::assertSame(['request.wont_do', 'user', self::WONT_DO_REASON, 'wont_do'], [$result['events'][0]['type'], $result['events'][0]['actor']['kind'], $result['events'][0]['body'], $result['events'][0]['statusAfter']]);
    }

    #[TestDox('Unassign restores the status before the claim')]
    public function testUnassignRestoresStatusBeforeClaim(): void
    {
        $open = (string) self::openRequest($this->client, $this->reviewId)['id'];
        $reopened = (string) self::openRequest($this->client, $this->reviewId)['id'];
        self::requestCommand($this->client, $reopened, 'wont-do', ['reason' => self::WONT_DO_REASON]);
        self::requestCommand($this->client, $reopened, 'reopen', ['note' => 'Integer cents still round twice.']);
        self::asAgent($this->user, static function (RequestCommands $commands, Actor $agent) use ($open, $reopened): void {
            $commands->claim(new Claim(RequestId::fromString($open)), $agent);
            $commands->claim(new Claim(RequestId::fromString($reopened)), $agent);
        });

        $releasedOpen = self::requestCommand($this->client, $open, 'release');
        $releasedReopened = self::requestCommand($this->client, $reopened, 'release', ['note' => 'Taking it back.']);

        self::assertSame(['open', null, null], [$releasedOpen['request']['status'], $releasedOpen['request']['claimedBy'], $releasedOpen['request']['claimedAt']]);
        self::assertSame(['reopened', null, null], [$releasedReopened['request']['status'], $releasedReopened['request']['claimedBy'], $releasedReopened['request']['claimedAt']]);
        self::assertSame(['request.released', 'open', null], [$releasedOpen['events'][0]['type'], $releasedOpen['events'][0]['statusAfter'], $releasedOpen['events'][0]['body']]);
        self::assertSame(['request.released', 'reopened', 'Taking it back.'], [$releasedReopened['events'][0]['type'], $releasedReopened['events'][0]['statusAfter'], $releasedReopened['events'][0]['body']]);
    }
}
