<?php

declare(strict_types=1);

namespace App\Index;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * {@see BlobSymbols} picks the extractor by {@see FileLanguage}.
 */
#[AutoconfigureTag]
interface SymbolExtractor
{
    /**
     * @return list<string> The V7 languages of the extractor, for example `php`
     */
    public function languages(): array;

    /**
     * A syntax error gives `parseError` and no symbols.
     *
     * @param string $language One of {@see languages()}
     */
    public function extract(string $language, string $source): SymbolTable;
}
