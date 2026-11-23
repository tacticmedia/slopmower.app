<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\CommentLabel;
use App\Domain\Request\CommentQuestion;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/IssueCommentInput. {@see \App\Application\IssueCommands::comment()} checks the body and
 * `questions` (D124).
 */
final readonly class IssueCommentInput
{
    /**
     * @param list<CommentQuestion>|null $questions
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
        public ?CommentLabel $label = null,
        #[Assert\Ulid]
        public ?string $replaces = null,
        public ?array $questions = null,
    ) {
    }
}
