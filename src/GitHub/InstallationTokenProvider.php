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
 * Every clone or fetch requests a new token, and only its caller holds it (architecture section 12).
 */
final class InstallationTokenProvider
{
    public const string API = 'https://api.github.com/';

    public const array API_HEADERS = [
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2026-03-10',
    ];

    /** Covers clock drift between the server and GitHub. */
    private const int JWT_BACKDATE_SECONDS = 60;

    /** GitHub refuses a JWT that lives longer. */
    private const int JWT_LIFETIME_SECONDS = 600;

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
     * A token for one repository with `contents: read`.
     *
     * @throws GitHubRequestFailed
     */
    public function forRepository(int $installationId, int $repositoryId): string
    {
        $status = 0;

        try {
            $response = $this->githubClient->request('POST', self::API."app/installations/{$installationId}/access_tokens", [
                'auth_bearer' => $this->appJwt(),
                'headers' => self::API_HEADERS,
                'json' => ['repository_ids' => [$repositoryId], 'permissions' => ['contents' => 'read']],
                // Keeps the token out of the profiler storage.
                'extra' => ['trace_content' => false],
            ]);
            $status = $response->getStatusCode();
            $token = 201 === $status ? ($response->toArray()['token'] ?? null) : null;
        } catch (HttpClientException $failure) {
            throw new GitHubRequestFailed('The installation token request failed.', 0, $failure);
        }

        if (!\is_string($token) || '' === $token) {
            throw new GitHubRequestFailed("GitHub answered {$status} to the installation token request.");
        }

        return $token;
    }

    /**
     * GitHub takes the client ID as issuer.
     *
     * @throws GitHubRequestFailed Without a usable App identity or key
     */
    private function appJwt(): string
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
}
