<?php

declare(strict_types=1);

namespace App\GitHub\Payload;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A GitHub installation object, as `GET /user/installations` and the installation webhooks send it.
 */
final readonly class InstallationPayload
{
    public function __construct(
        public int $id,
        public AccountPayload $account,
        #[SerializedName('suspended_at')]
        public ?\DateTimeImmutable $suspendedAt = null,
    ) {
    }
}
