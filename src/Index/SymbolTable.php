<?php

declare(strict_types=1);

namespace App\Index;

/**
 * The symbols of one blob at one path. A parse error and a file that no extractor supports have no symbols.
 */
final readonly class SymbolTable
{
    /** @var list<ExtractedSymbol> In the order of {@see ExtractedSymbol::compare()} */
    public array $symbols;

    /**
     * @param string|null           $language Null when no extractor supports the path
     * @param list<ExtractedSymbol> $symbols
     */
    public function __construct(
        public ?string $language,
        public bool $parseError,
        array $symbols,
    ) {
        usort($symbols, ExtractedSymbol::compare(...));
        $this->symbols = $symbols;
    }

    public static function unsupported(): self
    {
        return new self(null, false, []);
    }
}
