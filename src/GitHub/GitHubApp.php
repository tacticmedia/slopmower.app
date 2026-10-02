<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Domain\User\SignInMethod;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The GitHub App of the instance. An empty client ID or slug means that no App is configured.
 */
final class GitHubApp
{
    public function __construct(
        #[Autowire(env: 'GITHUB_APP_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'GITHUB_APP_SLUG')]
        private readonly string $slug,
    ) {
    }

    /**
     * `features.githubApp` of A1.
     *
     * @return array{slug: string, installUrl: string}|null
     */
    public function featuresFor(User $user): ?array
    {
        $installUrl = $this->installUrlFor($user);

        return null === $installUrl ? null : ['slug' => $this->slug, 'installUrl' => $installUrl];
    }

    /**
     * Also `githubConfigureUrl` of R2. Null without an App and for a local sign-in.
     */
    public function installUrlFor(User $user): ?string
    {
        if ('' === $this->clientId || '' === $this->slug || SignInMethod::GitHub !== $user->getSignInMethod()) {
            return null;
        }

        return 'https://github.com/apps/'.rawurlencode($this->slug).'/installations/new';
    }
}
