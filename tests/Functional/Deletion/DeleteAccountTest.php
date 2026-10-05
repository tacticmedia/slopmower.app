<?php

declare(strict_types=1);

namespace App\Tests\Functional\Deletion;

use App\Entity\User;
use App\Message\PurgeTenantJob;
use App\Security\LocalUserProvisioner;
use App\Security\TenantBeingDeleted;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Api\RequestApiFixtures;
use App\Tests\Support\Api\ReviewApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Environment\EnvironmentVariables;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Messenger\QueuedMessages;
use App\Tests\Support\Source\LocalSources;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;

/**
 * A2, up to the queued purge. The user "jan" has a review of the cloned source "billing", with a request, and two
 * API tokens.
 */
final class DeleteAccountTest extends WebTestCase
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

    /** @var array{repositoryId: string, reviewId: string, requestId: string} */
    private array $ids;

    private string $secret;

    protected function setUp(): void
    {
        $this->useTemporaryLocalSources();
        $this->newSource();
        $this->client = static::createClient();
        $this->user = self::signIn($this->client);
        $repositoryId = self::connectAndClone($this->client);
        $reviewId = (string) self::startReviewThroughApi($this->client, $repositoryId)['id'];
        self::runQueuedMessages();
        $this->ids = ['repositoryId' => $repositoryId, 'reviewId' => $reviewId, 'requestId' => (string) self::openRequest($this->client, $reviewId)['id']];
        [, $this->secret] = self::storeToken($this->user);
        self::storeToken($this->user, name: 'codex desktop');
    }

    #[TestDox('A confirm value that differs from the login gives 422')]
    public function testConfirmOtherThanLoginGivesInvalidInput(): void
    {
        $error = self::assertApiError($this->deleteAccount('Jan'), 422, 'invalid_input');

        self::assertSame('confirm', $error['details']['violations'][0]['field'] ?? null);
        self::assertSame([null], self::connection()->fetchFirstColumn('SELECT deletion_requested_at FROM tenant'));
        self::assertSame(0, (int) self::connection()->fetchOne('SELECT count(*) FROM api_token WHERE revoked_at IS NOT NULL'));
        self::assertApiResponse(self::apiRequest($this->client, 'GET', '/api/v1/me'), 200, 'me.schema.json');
    }

    #[TestDox('The response is 202 and clears the session cookie and the double-submit cookie')]
    public function testResponseIsAcceptedAndClearsTheSessionCookie(): void
    {
        [$sessionCookie] = $this->client->getCookieJar()->all();
        $doubleSubmitCookie = 'csrf-token_'.str_repeat('a', 24);
        $this->client->getCookieJar()->set(new Cookie($doubleSubmitCookie, 'csrf-token'));

        $response = $this->deleteAccount('jan');

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        $cleared = array_map(
            static fn (HttpCookie $cookie): string => $cookie->getName(),
            array_filter($response->headers->getCookies(), static fn (HttpCookie $cookie): bool => $cookie->isCleared()),
        );
        self::assertEqualsCanonicalizing([$sessionCookie->getName(), $doubleSubmitCookie], $cleared);
        self::assertEquals([new PurgeTenantJob($this->user->getTenantId())], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...self::asyncTransport()->getSent()]));
        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/me'), 401, 'unauthorized');
    }

    #[TestDox('All tokens are revoked and all IDs of the tenant answer 404')]
    public function testTokensAreRevokedAndIdsAnswerNotFound(): void
    {
        $this->deleteAccount('jan');

        self::assertSame(['api_tokens' => 2, 'revoked' => 2, 'marked_tenants' => 1], self::connection()->fetchAssociative(
            'SELECT (SELECT count(*) FROM api_token) AS api_tokens, (SELECT count(*) FROM api_token WHERE revoked_at IS NOT NULL) AS revoked,'
            .' (SELECT count(*) FROM tenant WHERE deletion_requested_at IS NOT NULL) AS marked_tenants',
        ));
        self::assertApiError(self::bearerRequest($this->client, $this->secret, 'GET', '/api/v1/me'), 401, 'unauthorized');
        self::signIn($this->client, 'other');
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/repositories/{$this->ids['repositoryId']}"), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/reviews/{$this->ids['reviewId']}"), 404, 'not_found');
        self::assertApiError(self::apiRequest($this->client, 'GET', "/api/v1/requests/{$this->ids['requestId']}"), 404, 'not_found');
    }

    #[TestDox('Another session of the same user is signed out at its next request')]
    public function testAnotherSessionIsSignedOutAtItsNextRequest(): void
    {
        [$otherSession] = $this->client->getCookieJar()->all();
        $this->client->loginUser($this->user, 'web');

        $this->deleteAccount('jan');
        $this->client->getCookieJar()->set(new Cookie($otherSession->getName(), $otherSession->getValue()));

        self::assertApiError(self::apiRequest($this->client, 'GET', '/api/v1/me'), 401, 'unauthorized');
        self::assertSame($this->user->getTenantId()->toRfc4122(), self::connection()->fetchOne('SELECT tenant_id FROM app_user'), 'The purge has not run.');
    }

    #[TestDox('A local sign-in of the user is refused, and no tenant is created')]
    public function testLocalSignInIsRefused(): void
    {
        $this->deleteAccount('jan');

        try {
            self::getContainer()->get(LocalUserProvisioner::class)->provision('jan');
            self::fail('The sign-in did not fail.');
        } catch (TenantBeingDeleted $refusal) {
            self::assertSame('The account is being deleted.', $refusal->getMessageKey());
        }

        self::assertSame(1, (int) self::connection()->fetchOne('SELECT count(*) FROM tenant'));
    }

    #[TestDox('An active token of the tenant gets 401')]
    public function testActiveTokenOfTheTenantGetsUnauthorized(): void
    {
        $this->deleteAccount('jan');
        [, $createdMeanwhile] = self::storeToken($this->user, name: 'created meanwhile');

        self::assertApiError(self::bearerRequest($this->client, $createdMeanwhile, 'GET', '/api/v1/me'), 401, 'unauthorized');
    }

    private function deleteAccount(string $confirm): Response
    {
        return self::apiRequest($this->client, 'DELETE', '/api/v1/account?confirm='.rawurlencode($confirm));
    }

    private static function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
