<?php

declare(strict_types=1);

namespace App\Index;

use App\Domain\Anchor\SymbolKind;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;
use Twig\TokenStream;

/**
 * D60: tokenizes only, so a template needs none of its extensions. FQNs: block `name`, macro `name()`.
 */
final class TwigSymbolExtractor implements SymbolExtractor
{
    /** The comments and the whitespace at the end of the text */
    private const string COMMENT_RUN = '/(?:\{#(?:(?!#\}).)*+#\}\s*+)++$/sD';

    private const string COMMENT = '/\{#(?:(?!#\}).)*+#\}/s';

    private readonly Environment $twig;

    public function __construct()
    {
        $this->twig = new Environment(new ArrayLoader());
    }

    public function languages(): array
    {
        return ['twig'];
    }

    public function extract(string $language, string $source): SymbolTable
    {
        try {
            return new SymbolTable($language, false, self::symbols($this->twig->tokenize(new Source($source, '')), $source));
        } catch (SyntaxError) {
            return new SymbolTable($language, true, []);
        }
    }

    /**
     * @return list<ExtractedSymbol>
     *
     * @throws SyntaxError A tag without its pair, or a tag that the end of the file cuts off
     */
    private static function symbols(TokenStream $stream, string $source): array
    {
        /** @var list<array{string, string, Token}> $open The tag, the name, and the `{%` of each open tag */
        $open = [];
        $symbols = [];

        while (!$stream->isEOF()) {
            $start = $stream->next();

            if (!$start->test(Token::BLOCK_START_TYPE)) {
                continue;
            }

            $tag = $stream->nextIf(Token::NAME_TYPE)?->getValue();
            $name = match ($tag) {
                'block', 'macro' => (string) $stream->expect(Token::NAME_TYPE)->getValue(),
                'endblock', 'endmacro' => $stream->test(Token::NAME_TYPE) ? (string) $stream->next()->getValue() : null,
                default => null,
            };
            $isShortBlock = 'block' === $tag && !$stream->test(Token::BLOCK_END_TYPE);
            $end = self::tagEnd($stream);

            if ($isShortBlock) {
                $symbols[] = self::symbol('block', (string) $name, $start, $end, $open, $source);
            } elseif ('block' === $tag || 'macro' === $tag || 'embed' === $tag) {
                $open[] = [$tag, (string) $name, $start];
            } elseif ('endblock' === $tag || 'endmacro' === $tag || 'endembed' === $tag) {
                [$openTag, $openName, $openStart] = array_pop($open) ?? throw new SyntaxError("No opening tag for {$tag}.");

                if ("end{$openTag}" !== $tag || ($name ?? $openName) !== $openName) {
                    throw new SyntaxError("The {$tag} does not close the {$openTag} {$openName}.");
                }

                $symbols[] = self::symbol($openTag, $openName, $openStart, $end, $open, $source);
            }
        }

        if ([] !== $open) {
            throw new SyntaxError('A tag has no end tag.');
        }

        return array_values(array_filter($symbols));
    }

    /**
     * @return Token The `%}` of the current tag
     *
     * @throws SyntaxError At the end of the file
     */
    private static function tagEnd(TokenStream $stream): Token
    {
        while (!$stream->test(Token::BLOCK_END_TYPE)) {
            $stream->next();
        }

        return $stream->getCurrent();
    }

    /**
     * Null for an embed, a block inside an embed, and a row that {@see ExtractedSymbol::storable()} refuses.
     *
     * @param list<array{string, string, Token}> $enclosing
     */
    private static function symbol(string $tag, string $name, Token $start, Token $end, array $enclosing, string $source): ?ExtractedSymbol
    {
        if ('embed' === $tag || ('block' === $tag && \in_array('embed', array_column($enclosing, 0), true))) {
            return null;
        }

        [$kind, $fqn] = 'block' === $tag ? [SymbolKind::Block, $name] : [SymbolKind::Function, "{$name}()"];
        $docStartLine = self::docStartLine($start, $source);

        return ExtractedSymbol::storable($kind, $fqn, $name, null, $docStartLine ?? $start->getLine(), $end->getLine(), $start->getLine(), $docStartLine);
    }

    /**
     * The lexer gives `{%` the text of the `{## #}` comments since the token before. Plain comments and whitespace may
     * stand between them.
     */
    private static function docStartLine(Token $start, string $source): ?int
    {
        if (null === $start->getDocumentation()) {
            return null;
        }

        $before = substr($source, 0, $start->getOffset() ?? 0);

        if (1 !== preg_match(self::COMMENT_RUN, $before, $run, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        preg_match_all(self::COMMENT, $run[0][0], $comments, \PREG_OFFSET_CAPTURE);

        foreach ($comments[0] as [$comment, $offset]) {
            if (str_starts_with($comment, '{##') && !str_starts_with($comment, '{##}')) {
                $linesBetween = substr_count(str_replace(["\r\n", "\r"], "\n", substr($before, $run[0][1] + $offset)), "\n");

                return $start->getLine() - $linesBetween;
            }
        }

        return null;
    }
}
