<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Request\RequestType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/UpdateDraftInput. The anchor cannot change. The draft refuses title, requireApproval, type,
 * and group on a reply.
 */
final readonly class UpdateDraftInput
{
    public function __construct(
        #[RequestTitle]
        public ?string $title = null,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
        public ?bool $requireApproval = null,
        public ?RequestType $type = null,
        #[RequestGroup]
        public ?string $group = null,
    ) {
    }
}
