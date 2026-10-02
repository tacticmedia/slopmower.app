<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `NAME(document, 'key', ...)`: the value at the key path, SQL NULL when a key is absent or a value on the way is not
 * an object.
 */
abstract class JsonbPathFunction extends FunctionNode
{
    private Node $document;

    /** @var list<Literal> */
    private array $keys = [];

    final public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->document = $parser->StringPrimary();

        do {
            $parser->match(TokenType::T_COMMA);
            $key = $parser->Literal();
            $this->keys[] = Literal::STRING === $key->type ? $key : $parser->syntaxError('a string literal');
        } while ($parser->getLexer()->isNextToken(TokenType::T_COMMA));

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    final protected function textAtPath(SqlWalker $sqlWalker): string
    {
        return "({$this->document->dispatch($sqlWalker)} #>> ARRAY[".implode(', ', array_map($sqlWalker->walkLiteral(...), $this->keys)).'])';
    }
}
