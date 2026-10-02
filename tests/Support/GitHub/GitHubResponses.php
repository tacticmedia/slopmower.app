<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The framework.http_client.mock_response_factory of the test environment. Serves the recorded responses in tests/Fixtures/github.
 */
final class GitHubResponses
{
    /** The token in oauth-access-token.json. */
    public const string ACCESS_TOKEN = 'ghu_16C7e42F292c6912E7710c838347Ae178B4a';
    public const string RENAMED_USER_ACCESS_TOKEN = 'ghu_renamedUserToken';
    public const string FORMER_LOGIN_USER_ACCESS_TOKEN = 'ghu_formerLoginUserToken';
    public const string REVOKED_ACCESS_TOKEN = 'ghu_revokedToken';

    private const array USER_FIXTURE_BY_ACCESS_TOKEN = [
        self::ACCESS_TOKEN => 'user',
        self::RENAMED_USER_ACCESS_TOKEN => 'user-renamed',
        self::FORMER_LOGIN_USER_ACCESS_TOKEN => 'user-taken-login',
        self::REVOKED_ACCESS_TOKEN => 'user-unauthorized',
    ];

    /**
     * @param array{normalized_headers?: array<string, list<string>>} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $fixture = match ("{$method} {$url}") {
            'POST https://github.com/login/oauth/access_token' => 'oauth-access-token',
            'GET https://api.github.com/user' => self::USER_FIXTURE_BY_ACCESS_TOKEN[self::bearerToken($options)] ?? null,
            'GET https://api.github.com/user/emails' => 'user-emails-forbidden',
            default => null,
        } ?? throw new \LogicException("No recorded GitHub response for {$method} {$url}.");

        /** @var array{status: int, headers: array<string, string>, body: string|array<mixed>} $recorded */
        $recorded = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/github/'.$fixture.'.json'), true, flags: \JSON_THROW_ON_ERROR);
        $body = \is_string($recorded['body']) ? $recorded['body'] : json_encode($recorded['body'], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => $recorded['status'], 'response_headers' => $recorded['headers']]);
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>} $options
     */
    private static function bearerToken(array $options): string
    {
        return str_replace('Authorization: Bearer ', '', $options['normalized_headers']['authorization'][0] ?? '');
    }
}
