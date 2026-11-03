<?php

declare(strict_types=1);

namespace App\Controller\Api\Input;

use App\Domain\Request\CommentQuestion;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * inputs.schema.json#/$defs/CommentInput. The decider checks the body, `question`, and `questions` (D107).
 */
final readonly class CommentInput
{
    /**
     * @param list<CommentQuestion>|null $questions
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(max: 65536)]
        public ?string $body = null,
        public ?bool $question = null,
        public ?array $questions = null,
    ) {
    }
}
