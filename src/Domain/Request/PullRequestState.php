<?php

declare(strict_types=1);

namespace App\Domain\Request;

/**
 * `PullRequestState` of common.schema.json.
 */
enum PullRequestState: string
{
    case Open = 'open';
    case Draft = 'draft';
    case Merged = 'merged';
    case Closed = 'closed';
    case Unknown = 'unknown';

    /**
     * @param string $state The GitHub `state`: `open` or `closed`
     */
    public static function ofGitHub(string $state, bool $merged, bool $draft): self
    {
        return match ($state) {
            'open' => $draft ? self::Draft : self::Open,
            'closed' => $merged ? self::Merged : self::Closed,
            default => self::Unknown,
        };
    }
}
