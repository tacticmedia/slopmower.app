<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decision D25: the local sign-in is on only in the dev environment and with a non-empty APP_LOCAL_SIGN_IN.
 */
final class LocalSignIn
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire(env: 'APP_LOCAL_SIGN_IN')]
        private readonly string $login,
    ) {
    }

    /**
     * Null when the local sign-in is off.
     */
    public function login(): ?string
    {
        return 'dev' === $this->environment && '' !== $this->login ? $this->login : null;
    }

    public function isSetOutsideDev(): bool
    {
        return 'dev' !== $this->environment && '' !== $this->login;
    }
}
