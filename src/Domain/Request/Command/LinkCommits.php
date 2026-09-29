<?php

declare(strict_types=1);

namespace App\Domain\Request\Command;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;
use App\Domain\Event\IdListViolations;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\RequestId;
use App\Domain\Input\TextInput;

final readonly class LinkCommits implements RequestCommand
{
    public const int MAX_REQUESTS = 50;

    /**
     * @param list<RequestId> $requestIds 1 to 50 unique IDs of one review, in the order the caller gave them
     */
    public function __construct(
        public CommitSha $commitSha,
        public array $requestIds,
        public ?string $note = null,
    ) {
        $violations = [
            ...IdListViolations::nonEmptySet($requestIds, 'requestIds'),
            ...(\count($requestIds) > self::MAX_REQUESTS ? [new Violation('requestIds', \sprintf('must hold at most %d IDs', self::MAX_REQUESTS))] : []),
            ...TextInput::optionalNote($note, 'note'),
        ];
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }
    }
}
