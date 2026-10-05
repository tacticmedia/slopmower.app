<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;

final readonly class SymbolRef
{
    private const int MAX_FQN_CHARACTERS = 1024;

    private function __construct(
        public string $language,
        public SymbolKind $kind,
        public string $fqn,
    ) {
    }

    public static function of(string $language, SymbolKind $kind, string $fqn): self
    {
        $violations = [];

        if ('' === $language) {
            $violations[] = ['field' => 'symbol.language', 'message' => 'Must not be empty.'];
        }

        if ('' === $fqn || mb_strlen($fqn, 'UTF-8') > self::MAX_FQN_CHARACTERS) {
            $violations[] = ['field' => 'symbol.fqn', 'message' => 'Must be 1 to 1024 characters.'];
        }

        if ([] !== $violations) {
            throw new InvalidInput($violations);
        }

        return new self($language, $kind, $fqn);
    }
}
