<?php

declare(strict_types=1);

namespace App\Git;

/**
 * A git configuration value that reaches git only through `GIT_CONFIG_KEY_<n>` and `GIT_CONFIG_VALUE_<n>`, decision D38.
 * JSON, which Monolog uses for objects in a log context, and var_dump() show it redacted.
 */
final readonly class GitConfigSecret implements \JsonSerializable
{
    public function __construct(
        public string $key,
        #[\SensitiveParameter]
        public string $value,
    ) {
        if ('' === $key || 1 === preg_match('/[\x00\n]/', $key)) {
            throw new \InvalidArgumentException('A git configuration key must be non-empty and contain no NUL byte or line break.');
        }
        if ('' === $value || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('A git secret must be non-empty and contain no NUL byte.');
        }
    }

    /**
     * @return array{key: string, value: string}
     */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'value' => GitCommand::REDACTED];
    }

    /**
     * @return array{key: string, value: string}
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
