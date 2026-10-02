<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Error\InvalidInput;

final readonly class RepositoryName implements \Stringable
{
    private const int MAX_CHARACTERS = 255;

    private function __construct(
        private RepositorySource $source,
        private string $value,
    ) {
    }

    public static function forSource(RepositorySource $source, string $value, string $field = 'name'): self
    {
        return match ($source) {
            RepositorySource::Github => self::github($value, $field),
            RepositorySource::Local => self::local($value, $field),
        };
    }

    public static function github(string $value, string $field = 'name'): self
    {
        $isValid = 1 === preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#D', $value)
            && strlen($value) <= self::MAX_CHARACTERS
            && !array_any(explode('/', $value), static fn (string $segment): bool => in_array($segment, ['.', '..'], true));

        if (!$isValid) {
            throw InvalidInput::field($field, 'Must be a GitHub repository name "owner/name" of at most 255 characters.');
        }

        return new self(RepositorySource::Github, $value);
    }

    /**
     * The `LocalDirectory` rules of repository.schema.json.
     */
    public static function local(string $value, string $field = 'name'): self
    {
        $isValid = mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= self::MAX_CHARACTERS
            && 1 === preg_match('/^(?!\.)[^\/\x00-\x1f]+$/uD', $value);

        if (!$isValid) {
            throw InvalidInput::field($field, 'Must be a directory name of at most 255 characters: no "/", no leading ".", no control character.');
        }

        return new self(RepositorySource::Local, $value);
    }

    public function source(): RepositorySource
    {
        return $this->source;
    }

    public function equals(self $other): bool
    {
        return $this->source === $other->source && match ($this->source) {
            RepositorySource::Github => 0 === strcasecmp($this->value, $other->value),
            RepositorySource::Local => $this->value === $other->value,
        };
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
