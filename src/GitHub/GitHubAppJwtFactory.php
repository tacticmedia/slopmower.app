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

/**
 * The App JWT for `POST /app/installations/{id}/access_tokens`. GitHub takes the client ID as issuer.
 */
final class GitHubAppJwtFactory
{
    /** Covers clock drift between the server and GitHub. */
    public const int BACKDATE_SECONDS = 60;

    /** GitHub refuses a JWT that lives longer. */
    public const int LIFETIME_SECONDS = 600;

    public function __construct(
        #[Autowire(env: 'GITHUB_APP_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'default::GITHUB_APP_PRIVATE_KEY')]
        #[\SensitiveParameter]
        private readonly ?string $privateKeyPem,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return non-empty-string
     *
     * @throws GitHubRequestFailed Without a usable App identity or key
     */
    public function create(): string
    {
        if ('' === $this->clientId || null === $this->privateKeyPem || '' === $this->privateKeyPem) {
            throw new GitHubRequestFailed('The GitHub App client ID or private key is not configured.');
        }

        // GitHub reads whole seconds.
        $issuedAt = new \DateTimeImmutable('@'.($this->clock->now()->getTimestamp() - self::BACKDATE_SECONDS));

        try {
            return Builder::new(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates())
                ->issuedBy($this->clientId)
                ->issuedAt($issuedAt)
                ->expiresAt($issuedAt->modify('+'.self::LIFETIME_SECONDS.' seconds'))
                ->getToken(new Sha256(), InMemory::plainText($this->privateKeyPem))
                ->toString();
        } catch (JwtException $failure) {
            throw new GitHubRequestFailed('The GitHub App private key cannot sign the App JWT.', 0, $failure);
        }
    }
}
