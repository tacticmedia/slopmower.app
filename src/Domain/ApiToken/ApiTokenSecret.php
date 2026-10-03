<?php

declare(strict_types=1);

namespace App\Domain\ApiToken;

/**
 * `hr_` and 32 random bytes in base64url without padding (docs/specs/rest-api-requests.md section 6).
 */
final readonly class ApiTokenSecret
{
    public const string PATTERN = '/^hr_[A-Za-z0-9_-]{43}$/D';

    private const string MARKER = 'hr_';
    private const int RANDOM_BYTES = 32;
    private const int PREFIX_CHARACTERS = 9;

    private function __construct(#[\SensitiveParameter] public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(self::MARKER.rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '='));
    }

    /**
     * @return self|null Null for a value that breaks {@see self::PATTERN}
     */
    public static function tryFrom(#[\SensitiveParameter] string $value): ?self
    {
        return 1 === preg_match(self::PATTERN, $value) ? new self($value) : null;
    }

    /**
     * Lower-case hex. SHA-256 without a salt suffices for 256 random bits, and it keeps the lookup by hash.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    public function prefix(): string
    {
        return substr($this->value, 0, self::PREFIX_CHARACTERS);
    }
}
