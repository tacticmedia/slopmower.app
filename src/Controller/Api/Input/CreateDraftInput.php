<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Entity\DraftKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CreateDraftInput.
 */
final readonly class CreateDraftInput
{
    public function __construct(
        #[Assert\NotNull]
        public ?DraftKind $kind = null,
        #[Assert\When(expression: static function (self $input): bool {
            return DraftKind::Reply === $input->kind;
        }, constraints: [new Assert\IsNull()])]
        #[Assert\Valid]
        public ?AnchorInput $anchor = null,
        #[Assert\When(expression: static function (self $input): bool {
            return DraftKind::Reply === $input->kind;
        }, constraints: [new Assert\NotNull()])]
        #[Assert\When(expression: static function (self $input): bool {
            return DraftKind::NewRequest === $input->kind;
        }, constraints: [new Assert\IsNull()])]
        #[Assert\Ulid]
        public ?string $requestId = null,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
    ) {
    }
}
