<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\SymbolKind;

/**
 * A name in PHP code, resolved to the FQN format of {@see PhpSymbolExtractor}. A function or constant name that PHP
 * resolves at run time has the global name as fallback: the first FQN with a declaration of one of the kinds wins.
 */
final readonly class Reference
{
    private const array CLASS_LIKE = [SymbolKind::Class_, SymbolKind::Interface, SymbolKind::Trait, SymbolKind::Enum];

    /**
     * @param non-empty-list<string>     $fqns  Most specific first
     * @param non-empty-list<SymbolKind> $kinds
     */
    private function __construct(
        public array $fqns,
        public array $kinds,
    ) {
    }

    public static function classLike(string $fqn): self
    {
        return new self([$fqn], self::CLASS_LIKE);
    }

    public static function function(string $fqn, string ...$fallbacks): self
    {
        return new self(array_map(static fn (string $name): string => "{$name}()", [$fqn, ...array_values($fallbacks)]), [SymbolKind::Function]);
    }

    public static function constant(string $fqn, string ...$fallbacks): self
    {
        return new self([$fqn, ...array_values($fallbacks)], [SymbolKind::Constant]);
    }

    public static function method(string $classFqn, string $name): self
    {
        return new self(["{$classFqn}::{$name}()"], [SymbolKind::Method]);
    }

    public static function property(string $classFqn, string $name): self
    {
        return new self(["{$classFqn}::\${$name}"], [SymbolKind::Property]);
    }

    /**
     * A class constant or an enum case.
     */
    public static function classConstant(string $classFqn, string $name): self
    {
        return new self(["{$classFqn}::{$name}"], [SymbolKind::ClassConstant, SymbolKind::EnumCase]);
    }
}
