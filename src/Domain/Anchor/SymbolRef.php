<?php

declare(strict_types=1);

namespace App\Domain\Anchor;

use App\Domain\Error\InvalidInput;
use App\Domain\Error\Violation;

/**
 * FQN example: `App\Billing\InvoiceTotals::total()`.
 */
final readonly class SymbolRef
{
    public const string LANGUAGE_PHP = 'php';
    private const int FQN_MAX_LENGTH = 1024;

    private function __construct(
        public string $language,
        public SymbolKind $kind,
        public string $fqn,
    ) {
    }

    public static function php(SymbolKind $kind, string $fqn, string $field = 'symbol'): self
    {
        return self::fromParts(self::LANGUAGE_PHP, $kind, $fqn, $field);
    }

    /**
     * The contract allows any language. The proof of concept indexes PHP only, so any other language is refused.
     */
    public static function fromParts(string $language, SymbolKind $kind, string $fqn, string $field = 'symbol'): self
    {
        $violations = [];
        if (self::LANGUAGE_PHP !== $language) {
            $violations[] = new Violation($field.'.language', 'must be php');
        }
        if ('' === $fqn || mb_strlen($fqn, 'UTF-8') > self::FQN_MAX_LENGTH) {
            $violations[] = new Violation($field.'.fqn', 'must have 1 to 1024 characters');
        }
        if ([] !== $violations) {
            throw InvalidInput::fromViolations($violations);
        }

        return new self($language, $kind, $fqn);
    }

    public function equals(self $other): bool
    {
        return $this->language === $other->language && $this->kind === $other->kind && $this->fqn === $other->fqn;
    }
}
