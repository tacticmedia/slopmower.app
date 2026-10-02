<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\RepoPath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface SymbolExtractor
{
    /**
     * The `language` of V9, for example `php`.
     */
    public function language(): string;

    public function supports(RepoPath $path): bool;

    /**
     * A syntax error gives `parseError` and no symbols.
     */
    public function extract(string $source): SymbolTable;
}
