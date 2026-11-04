<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The mock_response_factory of github.client in the test environment. Serves the recorded responses in tests/Fixtures/github.
 * The user token selects the user and the installation listing. The installation token endpoint answers 201 only for a
 * bearer token in JWT form and a token limited to one repository and one permission set of
 * {@see self::TOKEN_FIXTURE_BY_PERMISSIONS}, as the jobs ask for it. A repository read and a permission read answer only
 * the `contents: read` token, and a pull request read only the `pull_requests: read` token. The permission read answers
 * `none` for {@see self::FORMER_MEMBER_LOGIN}, 404 for {@see self::UNKNOWN_LOGIN}, and `write` for any other login. The
 * check run calls answer only the `checks: write` token, and {@see self::checkRunCalls()} keeps them. The Marketplace
 * account read and the installation read answer only the App JWT. An installation read answers what
 * {@see self::answerInstallation()} set, else 404. The code reads of Pass-through, with the installation token, run git on
 * the bare repository of the name under the `file://` GITHUB_GIT_BASE_URL of LocalGitHub, see {@see GitBackedGitHub}.
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
    /** The installation listing of ACCESS_TOKEN, whose acme repository has no default branch. */
    public const string MALFORMED_LISTING_ACCESS_TOKEN = 'ghu_malformedListingToken';
    /** The installation listing of ACCESS_TOKEN, whose acme installation also accepted `pull_requests: read`. */
    public const string PULL_REQUESTS_LISTING_ACCESS_TOKEN = 'ghu_pullRequestsListingToken';
    /** The installation listing of ACCESS_TOKEN, whose acme installation also accepted `pull_requests: read` and `checks: write`. */
    public const string CHECKS_LISTING_ACCESS_TOKEN = 'ghu_checksListingToken';
    /** The installation listing of CHECKS_LISTING_ACCESS_TOKEN, whose acme installation is suspended. */
    public const string SUSPENDED_CHECKS_LISTING_ACCESS_TOKEN = 'ghu_suspendedChecksListingToken';

    /** The token in installation-token.json, with `contents: read`. */
    public const string INSTALLATION_TOKEN = 'ghs_16C7e42F292c6912E7710c838347Ae178B4a';

    /** The token in installation-token-pull-requests.json, with `pull_requests: read`. */
    public const string PULL_REQUESTS_INSTALLATION_TOKEN = 'ghs_pullRequestsRead2F292c6912E7710c838347';

    /** The token in installation-token-checks.json, with `checks: write`. */
    public const string CHECKS_INSTALLATION_TOKEN = 'ghs_checksWrite16C7e42F292c6912E7710c8383';

    /** A login whose permission on every repository is `none`. */
    public const string FORMER_MEMBER_LOGIN = 'former-member';

    /** A login that GitHub does not know, for example the old login after a rename. */
    public const string UNKNOWN_LOGIN = 'renamed-away';

    private const array TOKEN_FIXTURE_BY_PERMISSIONS = [
        'installation-token' => ['contents' => 'read', 'metadata' => 'read'],
        'installation-token-pull-requests' => ['pull_requests' => 'read'],
        'installation-token-checks' => ['checks' => 'write'],
    ];

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

    /** `GET /repos/{owner}/{repo}`, which answers only the installation token, and 404 for another name. acme/billing has the size 2 MiB. */
    private const array REPOSITORY_FIXTURES = [
        'repos/acme/billing' => 'repository-acme-billing',
        'repos/octocat/Hello-World' => 'repository-octocat-hello-world',
    ];

    /** `GET /repos/{owner}/{repo}/pulls/{number}`. Any other number answers 404. */
    private const array PULL_REQUEST_FIXTURES = [
        'repos/acme/billing/pulls/42' => 'pull-request-acme-billing-42',
    ];

    /**
     * `GET /marketplace_listing/accounts/{account_id}`, built from the REST description of GitHub, because no listing
     * exists yet. Any other account answers 404. octocat has Pro on a free trial, with a pending change to Team. The
     * account 5 fails.
     */
    private const array MARKETPLACE_ACCOUNT_FIXTURES = [
        1 => 'marketplace-account-1',
        5 => 'server-error',
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
        self::MALFORMED_LISTING_ACCESS_TOKEN => ['user/installations/5551/repositories?per_page=100' => 'installation-5551-repositories-malformed'] + self::LISTING,
        self::PULL_REQUESTS_LISTING_ACCESS_TOKEN => ['user/installations?per_page=100' => 'user-installations-pull-requests'] + self::LISTING,
        self::CHECKS_LISTING_ACCESS_TOKEN => ['user/installations?per_page=100' => 'user-installations-checks'] + self::LISTING,
        self::SUSPENDED_CHECKS_LISTING_ACCESS_TOKEN => ['user/installations?per_page=100' => 'user-installations-checks-suspended'] + self::LISTING,
    ];

    /** @var list<array{string, string, array<mixed>}> Method, path below the API, and JSON body */
    private array $checkRunCalls = [];

    /** @var array<int, array<string, mixed>> `GET /app/installations/{id}` bodies by installation ID */
    private array $installations = [];

    /** @var list<string> The paths below `repos/` and `graphql` of the requests, in order */
    private array $repositoryCalls = [];

    /** @var list<mixed> The JSON bodies of the installation token requests, in order */
    private array $installationTokenRequests = [];

    /** @var array<string, array{int, array<string, string>, string}> Status, headers, and body by path prefix below `repos/{owner}/{name}/` */
    private array $codeReadAnswers = [];

    /** @var array<string, GitBackedGitHub> */
    private array $gitBacked = [];

    public function __construct(private readonly string $gitHubGitBaseUrl = '')
    {
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>, body?: mixed} $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $request = "{$method} {$url}";
        $code = $this->codeAnswer($method, $url, $options);

        if (null !== $code) {
            return $code;
        }

        if (1 === preg_match('~^GET https://api\.github\.com/app/installations/(\d+)$~', $request, $installation) && self::isAppJwt($options) && isset($this->installations[(int) $installation[1]])) {
            return new MockResponse(json_encode($this->installations[(int) $installation[1]], \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json; charset=utf-8']]);
        }

        $fixture = match (true) {
            'POST https://github.com/login/oauth/access_token' === $request => 'oauth-access-token',
            'GET https://api.github.com/user' === $request => self::USER_FIXTURE_BY_ACCESS_TOKEN[self::bearerToken($options)] ?? null,
            'GET https://api.github.com/user/emails' === $request => 'user-emails-forbidden',
            str_starts_with($request, 'GET '.self::API.'user/installations') => self::LISTING_FIXTURES_BY_ACCESS_TOKEN[self::bearerToken($options)][substr($url, \strlen(self::API))] ?? null,
            1 === preg_match('~^POST https://api\.github\.com/app/installations/\d+/access_tokens$~', $request) => $this->installationTokenFixture($options),
            1 === preg_match('~^GET https://api\.github\.com/repos/[^/]+/[^/]+/pulls/\d+$~', $request) => self::PULL_REQUESTS_INSTALLATION_TOKEN === self::bearerToken($options)
                ? self::PULL_REQUEST_FIXTURES[substr($url, \strlen(self::API))] ?? 'pull-request-not-found'
                : 'user-unauthorized',
            1 === preg_match('~^(POST|PATCH) https://api\.github\.com/repos/[^/]+/[^/]+/check-runs(/\d+)?$~', $request) => $this->checkRunFixture($method, $url, $options),
            1 === preg_match('~^GET https://api\.github\.com/repos/[^/]+/[^/]+/collaborators/([^/]+)/permission$~', $request, $collaborator) => self::INSTALLATION_TOKEN === self::bearerToken($options)
                ? self::permissionFixture($collaborator[1])
                : 'user-unauthorized',
            str_starts_with($request, 'GET '.self::API.'repos/') => self::INSTALLATION_TOKEN === self::bearerToken($options)
                ? self::REPOSITORY_FIXTURES[substr($url, \strlen(self::API))] ?? 'repository-not-found'
                : 'user-unauthorized',
            1 === preg_match('~^GET https://api\.github\.com/app/installations/\d+$~', $request) => self::isAppJwt($options) ? 'installation-not-found' : 'user-unauthorized',
            1 === preg_match('~^GET https://api\.github\.com/marketplace_listing/accounts/(\d+)$~', $request, $account) => self::isAppJwt($options)
                ? self::MARKETPLACE_ACCOUNT_FIXTURES[(int) $account[1]] ?? 'marketplace-account-not-found'
                : 'user-unauthorized',
            default => null,
        } ?? throw new \LogicException("No recorded GitHub response for {$method} {$url}.");

        /** @var array{status: int, headers: array<string, string>, body: string|array<mixed>} $recorded */
        $recorded = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/github/'.$fixture.'.json'), true, flags: \JSON_THROW_ON_ERROR);
        $body = \is_string($recorded['body']) ? $recorded['body'] : json_encode($recorded['body'], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => $recorded['status'], 'response_headers' => $recorded['headers']]);
    }

    /**
     * The installation 5551 of the organization acme, as `GET /app/installations/{id}` answers it from now on.
     *
     * @param array<string, string> $permissions
     */
    public function answerInstallation(?string $suspendedAt, array $permissions): void
    {
        $this->installations[5551] = [
            'id' => 5551,
            'account' => ['login' => 'acme', 'id' => 9919, 'type' => 'Organization'],
            'suspended_at' => $suspendedAt,
            'permissions' => $permissions,
        ];
    }

    /**
     * From now on, a code read of Pass-through whose path below `repos/{owner}/{name}/`, or `graphql`, starts with the
     * prefix gets the status, the headers, and the body.
     *
     * @param array<string, string> $headers
     */
    public function answerCodeRead(string $pathPrefix, int $status, array $headers = [], string $body = '{"message":"Answered by the test"}'): void
    {
        $this->codeReadAnswers[$pathPrefix] = [$status, $headers, $body];
    }

    /**
     * @return list<string> The paths below `repos/` and `graphql` of the requests so far
     */
    public function repositoryCalls(): array
    {
        return $this->repositoryCalls;
    }

    /**
     * @return list<mixed> The JSON bodies of the installation token requests so far
     */
    public function installationTokenRequests(): array
    {
        return $this->installationTokenRequests;
    }

    public function gitBacked(string $fullName): GitBackedGitHub
    {
        $gitDir = substr($this->gitHubGitBaseUrl, \strlen('file://'))."/{$fullName}.git";

        return $this->gitBacked[$fullName] ??= new GitBackedGitHub($gitDir, self::API."repos/{$fullName}/");
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>, body?: mixed} $options
     */
    private function codeAnswer(string $method, string $url, array $options): ?MockResponse
    {
        $isGraphQl = 'POST '.self::API.'graphql' === "{$method} {$url}";

        if (!$isGraphQl && 1 !== preg_match('~^GET https://api\.github\.com/repos/([^/]+/[^/]+)(?:/(.+))?$~', "{$method} {$url}", $match)) {
            return null;
        }

        $this->repositoryCalls[] = $isGraphQl ? 'graphql' : substr($url, \strlen(self::API.'repos/'));
        $body = $isGraphQl && \is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
        $variables = \is_array($body) && \is_array($body['variables'] ?? null) ? $body['variables'] : [];
        $fullName = $isGraphQl ? ($variables['owner'] ?? '').'/'.($variables['name'] ?? '') : $match[1];
        $path = $isGraphQl ? 'graphql' : $match[2] ?? '';

        if (!str_starts_with($this->gitHubGitBaseUrl, 'file://') || !is_dir(substr($this->gitHubGitBaseUrl, \strlen('file://'))."/{$fullName}.git") || '' === $path || str_starts_with($path, 'collaborators/') || str_starts_with($path, 'pulls/') || str_starts_with($path, 'check-runs')) {
            return null;
        }

        if (self::INSTALLATION_TOKEN !== self::bearerToken($options)) {
            return new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]);
        }

        foreach ($this->codeReadAnswers as $prefix => [$status, $headers, $body]) {
            if (str_starts_with($path, $prefix)) {
                return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
            }
        }

        $gitBacked = $this->gitBacked((string) $fullName);
        $headers = ['accept' => implode(',', $options['normalized_headers']['accept'] ?? [])];

        return $isGraphQl ? $gitBacked->blame(\is_array($body) ? $body : []) : $gitBacked->answer($path, $headers);
    }

    /**
     * @return list<array{string, string, array<mixed>}> The check run calls with the `checks: write` token: method,
     *                                                   path below the API, and JSON body
     */
    public function checkRunCalls(): array
    {
        return $this->checkRunCalls;
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>, body?: mixed} $options
     */
    private function checkRunFixture(string $method, string $url, array $options): string
    {
        if (self::CHECKS_INSTALLATION_TOKEN !== self::bearerToken($options)) {
            return 'user-unauthorized';
        }

        $path = substr($url, \strlen(self::API));
        $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true, flags: \JSON_THROW_ON_ERROR);
        $this->checkRunCalls[] = [$method, $path, \is_array($body) ? $body : []];

        return 'POST' === $method ? 'check-run-created' : 'check-run-updated';
    }

    private static function permissionFixture(string $login): string
    {
        return match ($login) {
            self::FORMER_MEMBER_LOGIN => 'repository-permission-none',
            self::UNKNOWN_LOGIN => 'user-not-found',
            default => 'repository-permission-write',
        };
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>, body?: mixed} $options
     */
    private function installationTokenFixture(array $options): string
    {
        $isAppJwt = self::isAppJwt($options);
        $body = \is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
        $this->installationTokenRequests[] = $body;
        $isLimited = \is_array($body)
            && ['permissions', 'repository_ids'] === array_keys(self::sorted($body))
            && \is_array($body['repository_ids']) && 1 === \count($body['repository_ids']) && \is_int($body['repository_ids'][0] ?? null);
        $fixture = $isAppJwt && $isLimited ? array_search($body['permissions'], self::TOKEN_FIXTURE_BY_PERMISSIONS, true) : false;

        return \is_string($fixture) ? $fixture : 'installation-token-invalid';
    }

    /**
     * @param array{normalized_headers?: array<string, list<string>>} $options
     */
    private static function isAppJwt(array $options): bool
    {
        return 1 === preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/', self::bearerToken($options));
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
