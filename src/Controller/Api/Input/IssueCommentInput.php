<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\CommentLabel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/IssueCommentInput. {@see \App\Application\IssueCommands::comment()} refuses whitespace only.
 */
final readonly class IssueCommentInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
        public ?CommentLabel $label = null,
    ) {
    }
}
