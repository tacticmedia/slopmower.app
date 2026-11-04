<?php

declare(strict_types=1);

namespace App\GitHub;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A token has the permissions of one job, and only its caller holds it (architecture section 12, D64). The process keeps
 * each token until 5 minutes before it expires, by installation, repository, and permissions (D112).
 */
final class InstallationTokenProvider
{
    public const string API = 'https://api.github.com/';

    public const array API_HEADERS = [
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2026-03-10',
    ];

    /** A clone, a fetch, a repository read, and the permission read of D88, which needs Metadata: read. */
    public const array CONTENTS_READ = ['contents' => 'read', 'metadata' => 'read'];

    public const array PULL_REQUESTS_READ = ['pull_requests' => 'read'];

    public const array CHECKS_WRITE = ['checks' => 'write'];

    /** Covers clock drift between the server and GitHub. */
    private const int JWT_BACKDATE_SECONDS = 60;

    /** GitHub refuses a JWT that lives longer. */
    private const int JWT_LIFETIME_SECONDS = 600;

    private const int TOKEN_REUSE_MARGIN_SECONDS = 300;

    /** @var array<string, array{string, int}> The token and its expiry in Unix seconds */
    private array $repositoryTokens = [];

    public function __construct(
        #[Target('github.client')]
        private readonly HttpClientInterface $githubClient,
        #[Autowire(env: 'GITHUB_APP_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'default::GITHUB_APP_PRIVATE_KEY')]
        #[\SensitiveParameter]
        private readonly ?string $privateKeyPem,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * A token for one repository. GitHub refuses permissions that the installation has not accepted, so a job checks
     * {@see \App\Entity\GitHubInstallation::accepts()} first.
     *
     * @param array<string, string> $permissions The permissions of the job, for example {@see self::CONTENTS_READ}
     *
     * @throws GitHubRequestFailed
     */
    public function forRepository(int $installationId, int $repositoryId, array $permissions): string
    {
        return $this->withExpiry($installationId, $repositoryId, $permissions)[0];
    }

    /**
     * {@see forRepository()} with the expiry of the kept token.
     *
     * @param array<string, string> $permissions
     *
     * @return array{string, \DateTimeImmutable}
     *
     * @throws GitHubRequestFailed
     */
    public function withExpiry(int $installationId, int $repositoryId, array $permissions): array
    {
        $key = self::tokenKey($installationId, $repositoryId, $permissions);
        [$kept, $expiresAtUnixSeconds] = $this->repositoryTokens[$key] ?? ['', 0];

        if ($expiresAtUnixSeconds - self::TOKEN_REUSE_MARGIN_SECONDS > $this->clock->now()->getTimestamp()) {
            return [$kept, new \DateTimeImmutable("@{$expiresAtUnixSeconds}")];
        }

        [$token, $expiresAt] = $this->mint($installationId, $repositoryId, $permissions);
        $this->repositoryTokens[$key] = [$token, $expiresAt->getTimestamp()];

        return [$token, $expiresAt];
    }

    /**
     * @param array<string, string> $permissions
     *
     * @return array{string, \DateTimeImmutable} The token and its expiry
     *
     * @throws GitHubRequestFailed
     */
    private function mint(int $installationId, int $repositoryId, array $permissions): array
    {
        $status = 0;

        try {
            $response = $this->githubClient->request('POST', self::API."app/installations/{$installationId}/access_tokens", [
                'auth_bearer' => $this->appJwt(),
                'headers' => self::API_HEADERS,
                'json' => ['repository_ids' => [$repositoryId], 'permissions' => $permissions],
                // Keeps the token out of the profiler storage.
                'extra' => ['trace_content' => false],
            ]);
            $status = $response->getStatusCode();
            $body = 201 === $status ? $response->toArray() : [];
        } catch (HttpClientException $failure) {
            throw new GitHubRequestFailed('The installation token request failed.', 0, $failure);
        }

        $token = $body['token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new GitHubRequestFailed("GitHub answered {$status} to the installation token request.");
        }

        $expiresAt = \is_string($body['expires_at'] ?? null) ? strtotime($body['expires_at']) : false;

        // GitHub gives every token an expiry, so a missing one makes a token that is never kept.
        return [$token, new \DateTimeImmutable('@'.(false === $expiresAt ? $this->clock->now()->getTimestamp() : $expiresAt))];
    }

    /**
     * Drops a kept token, for example after GitHub refused it.
     *
     * @param array<string, string> $permissions
     */
    public function forget(int $installationId, int $repositoryId, array $permissions): void
    {
        unset($this->repositoryTokens[self::tokenKey($installationId, $repositoryId, $permissions)]);
    }

    /**
     * The App JWT, for the endpoints that authenticate the App itself. GitHub takes the client ID as issuer.
     *
     * @throws GitHubRequestFailed Without a usable App identity or key
     */
    public function appJwt(): string
    {
        if ('' === $this->clientId || null === $this->privateKeyPem || '' === $this->privateKeyPem) {
            throw new GitHubRequestFailed('The GitHub App client ID or private key is not configured.');
        }

        $issuedAt = new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() - self::JWT_BACKDATE_SECONDS));

        try {
            return Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
                ->issuedBy($this->clientId)
                ->issuedAt($issuedAt)
                ->expiresAt($issuedAt->modify('+'.self::JWT_LIFETIME_SECONDS.' seconds'))
                ->getToken(new Sha256(), InMemory::plainText($this->privateKeyPem))
                ->toString();
        } catch (JwtException $failure) {
            throw new GitHubRequestFailed('The GitHub App private key cannot sign the App JWT.', 0, $failure);
        }
    }

    /**
     * @param array<string, string> $permissions
     */
    private static function tokenKey(int $installationId, int $repositoryId, array $permissions): string
    {
        ksort($permissions);

        return "{$installationId}:{$repositoryId}:".json_encode($permissions, \JSON_THROW_ON_ERROR);
    }
}
