<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `JSONB_CONTAINS(document, json) = TRUE` is PostgreSQL `document @> json`.
 */
final class JsonbContains extends FunctionNode
{
    private Node $document;

    private Node $fragment;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->document = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->fragment = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return "({$this->document->dispatch($sqlWalker)} @> CAST({$this->fragment->dispatch($sqlWalker)} AS jsonb))";
    }
}
