<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The mock_response_factory of github.client in the test environment. Serves the recorded responses in tests/Fixtures/github.
 * The user token selects the user and the installation listing. The installation token endpoint answers 201 only for a
 * signed App JWT and a token limited to one repository with `contents: read`, as the App asks for it.
 */
final class GitHubResponses
{
    /** The token in oauth-access-token.json. */
    public const string ACCESS_TOKEN = 'ghu_16C7e42F292c6912E7710c838347Ae178B4a';
    public const string RENAMED_USER_ACCESS_TOKEN = 'ghu_renamedUserToken';
    public const string FORMER_LOGIN_USER_ACCESS_TOKEN = 'ghu_formerLoginUserToken';
    public const string REVOKED_ACCESS_TOKEN = 'ghu_revokedToken';
    /** The installation listing of ACCESS_TOKEN, without acme/billing. */
    public const string BILLING_REMOVED_ACCESS_TOKEN = 'ghu_billingRemovedToken';
    /** The installation listing of ACCESS_TOKEN, whose second page of acme repositories fails. */
    public const string PARTIAL_LISTING_ACCESS_TOKEN = 'ghu_partialListingToken';
    /** The installation listing fails. */
    public const string FAILED_LISTING_ACCESS_TOKEN = 'ghu_failedListingToken';

    /** The token in installation-token.json. */
    public const string INSTALLATION_TOKEN = 'ghs_16C7e42F292c6912E7710c838347Ae178B4a';

    private const string API = 'https://api.github.com/';

    private const array USER_FIXTURE_BY_ACCESS_TOKEN = [
        self::ACCESS_TOKEN => 'user',
        self::RENAMED_USER_ACCESS_TOKEN => 'user-renamed',
        self::FORMER_LOGIN_USER_ACCESS_TOKEN => 'user-taken-login',
        self::REVOKED_ACCESS_TOKEN => 'user-unauthorized',
    ];

    private const array LISTING = [
        'user/installations?per_page=100' => 'user-installations',
        'user/installations/5551/repositories?per_page=100' => 'installation-5551-repositories',
        'user/installations/5552/repositories?per_page=100' => 'installation-5552-repositories',
    ];

    /** @var array<string, array<string, string>> Paths below the API by user token */
    private const array LISTING_FIXTURES_BY_ACCESS_TOKEN = [
        self::ACCESS_TOKEN => self::LISTING,
        self::BILLING_REMOVED_ACCESS_TOKEN => ['user/installations/5551/repositories?per_page=100' => 'installation-5551-repositories-without-billing'] + self::LISTING,
        self::PARTIAL_LISTING_ACCESS_TOKEN => [
            'user/installations/5551/repositories?per_page=100' => 'installation-5551-repositories-page-1',
            'user/installations/5551/repositories?per_page=100&page=2' => 'server-error',
        ] + self::LISTING,
        self::FAILED_LISTING_ACCESS_TOKEN => ['user/installations?per_page=100' => 'server-error'],
    ];

    /**
     * @param array{normalized_headers?: array<string, list<string>>, body?: mixed} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $request = "{$method} {$url}";
        $fixture = match (true) {
            'POST https://github.com/login/oauth/access_token' === $request => 'oauth-access-token',
            'GET https://api.github.com/user' === $request => self::USER_FIXTURE_BY_ACCESS_TOKEN[self::bearerToken($options)] ?? null,
            'GET https://api.github.com/user/emails' === $request => 'user-emails-forbidden',
            str_starts_with($request, 'GET '.self::API.'user/installations') => self::LISTING_FIXTURES_BY_ACCESS_TOKEN[self::bearerToken($options)][substr($url, \strlen(self::API))] ?? null,
            1 === preg_match('~^POST https://api\.github\.com/app/installations/\d+/access_tokens$~', $request) => self::installationTokenFixture($options),
            default => null,
        } ?? throw new \LogicException("No recorded GitHub response for {$method} {$url}.");

        /** @var array{status: int, headers: array<string, string>, body: string|array<mixed>} $recorded */
        $recorded = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/github/'.$fixture.'.json'), true, flags: \JSON_THROW_ON_ERROR);
        $body = \is_string($recorded['body']) ? $recorded['body'] : json_encode($recorded['body'], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => $recorded['status'], 'response_headers' => $recorded['headers']]);
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>, body?: mixed} $options
     */
    private static function installationTokenFixture(array $options): string
    {
        $isAppJwt = 1 === preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/', self::bearerToken($options));
        $body = \is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
        $isLimited = \is_array($body)
            && ['permissions', 'repository_ids'] === array_keys(self::sorted($body))
            && ['contents' => 'read'] === $body['permissions']
            && \is_array($body['repository_ids']) && 1 === \count($body['repository_ids']) && \is_int($body['repository_ids'][0] ?? null);

        return $isAppJwt && $isLimited ? 'installation-token' : 'installation-token-invalid';
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>} $options
     */
    private static function bearerToken(array $options): string
    {
        return str_replace('Authorization: Bearer ', '', $options['normalized_headers']['authorization'][0] ?? '');
    }
}
