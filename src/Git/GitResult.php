<?php

declare(strict_types=1);

namespace App\Git;

final readonly class GitResult
{
    /**
     * @param string $output      raw bytes
     * @param string $errorOutput with the secrets of the command redacted
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
