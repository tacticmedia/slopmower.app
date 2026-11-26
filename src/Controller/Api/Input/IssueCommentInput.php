<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Issue\CommentLabel;
use App\Domain\Issue\WaitingFor;
use App\Domain\Request\CommentQuestion;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/IssueCommentInput. {@see \App\Application\IssueCommands::comment()} checks the body,
 * `questions`, `dismiss` (D124), `waitingFor`, and `planRevision` (D136).
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
        public bool $dismiss = false,
        public ?WaitingFor $waitingFor = null,
        #[Assert\Ulid]
        public ?string $planRevision = null,
        #[Assert\Length(min: 1, max: 64)]
        public ?string $session = null,
    ) {
    }
}
