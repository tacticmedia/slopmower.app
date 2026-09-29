<?php

declare(strict_types=1);

namespace App\Domain\Review\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Git\RefName;
use App\Domain\Input\TextInput;

final readonly class StartReview implements ReviewCommand
{
    public const int MAX_TITLE_CHARACTERS = 200;

    /**
     * @param CommitSha $commitSha the ref resolved in the server clone
     */
    public function __construct(
        public string $title,
        public RefName $ref,
        public CommitSha $commitSha,
    ) {
        $violations = TextInput::violations($title, 'title', minCharacters: 1, maxCharacters: self::MAX_TITLE_CHARACTERS);
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
