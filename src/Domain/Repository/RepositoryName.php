<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Error\InvalidInput;

/**
 * GitHub names always contain one `/`, local names never do, so the name alone tells the source.
 */
final readonly class RepositoryName implements \Stringable
{
    private const int MAX_LENGTH = 255;
    private const string GITHUB_PATTERN = '#^[^/]+/[^/]+$#Du';
    // `LocalDirectory` of repository.schema.json.
    private const string LOCAL_PATTERN = '#^(?!\.)[^/\x00-\x1f]+$#Du';

    private function __construct(
        private RepositorySource $source,
        private string $value,
    ) {
    }

    public static function gitHub(string $value, string $field = 'name'): self
    {
        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || 1 !== preg_match(self::GITHUB_PATTERN, $value)) {
            throw InvalidInput::field($field, 'must be owner/name with at most 255 characters');
        }

        return new self(RepositorySource::GitHub, $value);
    }

    public static function local(string $value, string $field = 'name'): self
    {
        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH || 1 !== preg_match(self::LOCAL_PATTERN, $value)) {
            throw InvalidInput::field($field, 'must be a directory name of 1 to 255 characters with no /, no leading ., and no control characters');
        }

        return new self(RepositorySource::Local, $value);
    }

    public static function fromSource(RepositorySource $source, string $value, string $field = 'name'): self
    {
        return match ($source) {
            RepositorySource::GitHub => self::gitHub($value, $field),
            RepositorySource::Local => self::local($value, $field),
        };
    }

    public function source(): RepositorySource
    {
        return $this->source;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->source === $other->source && $this->value === $other->value;
    }
}
