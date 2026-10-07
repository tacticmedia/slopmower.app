<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/UpdateDraftInput. The anchor cannot change. The draft refuses requireApproval on a reply.
 */
final readonly class UpdateDraftInput
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
        public ?bool $requireApproval = null,
    ) {
    }
}
