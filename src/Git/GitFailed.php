<?php

declare(strict_types=1);

namespace App\Git;

/**
 * Neither the message nor the summary contains a secret: {@see GitRunner} redacts them.
 */
final class GitFailed extends \RuntimeException
{
    public readonly string $summary;

    /**
     * @param string|null $summary The message without the git arguments, which hold server paths. Null for a message without arguments.
     */
    public function __construct(string $message, ?string $summary = null)
    {
        parent::__construct($message);
        $this->summary = $summary ?? $message;
    }
}
