<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CreateTokenInput. The expiry window needs the clock, so the issuer checks it.
 */
final readonly class CreateTokenInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(min: 1, max: 64)]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9 ._@():-]*$/D', message: 'Must start with a letter or digit, then letters, digits, spaces, or . _ @ ( ) : -.')]
        public ?string $name = null,
        #[Assert\Ulid]
        public ?string $repositoryId = null,
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
