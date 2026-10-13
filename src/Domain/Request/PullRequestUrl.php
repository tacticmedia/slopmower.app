<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Error\InvalidInput;
use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\RepositorySource;
use App\Domain\StringValue;

/**
 * The `PullRequestUrl` rule of common.schema.json. {@see self::forRepository()} adds the GitHub match of Q17.
 */
final readonly class PullRequestUrl implements \Stringable
{
    use StringValue;

    private const int MAX_CHARACTERS = 2048;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value, string $field = 'url'): self
    {
        $isValid = mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= self::MAX_CHARACTERS
            && 1 === preg_match('#^https://\S+$#uD', $value);

        return $isValid ? new self($value) : throw InvalidInput::field($field, 'Must be an https URL of at most 2,048 characters without whitespace.');
    }

    /**
     * For a GitHub repository, the canonical URL with the repository name as connected, so a URL in another letter
     * case names the same pull request. A local repository accepts any URL of {@see self::fromString()} as written.
     */
    public static function forRepository(string $value, RepositoryName $repository, string $field = 'url'): self
    {
        $url = self::fromString($value, $field);

        if (RepositorySource::Github !== $repository->source()) {
            return $url;
        }

        $prefix = "https://github.com/{$repository}/pull/";
        $number = substr($value, \strlen($prefix));

        if (0 !== strncasecmp($value, $prefix, \strlen($prefix)) || 1 !== preg_match('/^[1-9][0-9]*$/D', $number)) {
            throw InvalidInput::field($field, "Must be a pull request of {$repository}: {$prefix}{number}.");
        }

        return new self($prefix.$number);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The number of a URL in the form of {@see self::forRepository()} with any repository name, so also of a link
     * under an earlier name of the repository.
     */
    public function gitHubNumber(): ?int
    {
        return 1 === preg_match('#^https://github\.com/[^/]+/[^/]+/pull/([1-9][0-9]*)$#D', $this->value, $match) ? (int) $match[1] : null;
    }

    /**
     * The same pull request: for a GitHub repository the same number (D64), else the same URL.
     */
    public function namesSamePullRequest(self $other, RepositorySource $source): bool
    {
        return RepositorySource::Github === $source
            ? null !== $this->gitHubNumber() && $this->gitHubNumber() === $other->gitHubNumber()
            : $this->equals($other);
    }
}
