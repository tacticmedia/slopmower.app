<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Git\RepoPath;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * FQNs: `Ns\Type`, `Ns\Type::method()`, `Ns\Type::$property`, `Ns\Type::CONSTANT`,
 * `Ns\Enum::Case`, `Ns\function()`, `Ns\CONSTANT`. Promoted constructor parameters are properties.
 */
final class PhpSymbolExtractor
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    }

    public function language(): string
    {
        return 'php';
    }

    public function supports(RepoPath $path): bool
    {
        return str_ends_with(strtolower($path->toString()), '.php');
    }

    /**
     * A syntax error gives `parseError` and no symbols. The NameResolver also throws a parse error, for example for a
     * duplicate `use` alias.
     */
    public function extract(string $source): SymbolTable
    {
        $collector = new PhpSymbolCollector();

        try {
            $statements = $this->parser->parse($source) ?? throw new Error('The parser returned no statements.');
            new NodeTraverser(new NameResolver(), $collector)->traverse($statements);
        } catch (Error) {
            return new SymbolTable($this->language(), true, []);
        }

        return new SymbolTable($this->language(), false, $collector->symbols());
    }
}
