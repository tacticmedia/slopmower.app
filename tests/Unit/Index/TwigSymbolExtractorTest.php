<?php

declare(strict_types=1);

namespace App\Tests\Unit\Index;

use App\Index\ExtractedSymbol;
use App\Index\TwigSymbolExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TwigSymbolExtractorTest extends KernelTestCase
{
    #[TestDox('Long, nested, and short blocks and a macro give rows, blocks inside embed are skipped, unknown filters and tags are no error')]
    public function testFixtureGivesTheExpectedTable(): void
    {
        $table = new TwigSymbolExtractor()->extract('twig', (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/twig/invoice.html.twig'));

        self::assertSame(['twig', false], [$table->language, $table->parseError]);
        self::assertSame([
            ['block', 'title', 'title', null, 3, 5, 3, null],
            ['block', 'body', 'body', null, 10, 24, 13, 10],
            ['block', 'lines', 'lines', null, 16, 20, 16, null],
            ['function', 'row()', 'row', null, 26, 28, 26, null],
        ], array_map(static fn (ExtractedSymbol $symbol): array => [
            $symbol->kind->value,
            $symbol->fqn,
            $symbol->name,
            $symbol->parentFqn,
            $symbol->range->startLine,
            $symbol->range->endLine,
            $symbol->declarationStartLine,
            $symbol->docStartLine,
        ], $table->symbols), 'The range of body starts at the first {## of the comments before it.');
    }

    #[TestDox('An unclosed {%, an endblock without a block, and a mismatched endblock name give a parse error')]
    #[DataProvider('brokenTemplates')]
    public function testBrokenTemplateGivesAParseError(string $source): void
    {
        $table = new TwigSymbolExtractor()->extract('twig', $source);

        self::assertSame(['twig', true, []], [$table->language, $table->parseError, $table->symbols]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenTemplates(): iterable
    {
        yield 'unclosed {% before a line break' => ["{% block body %}\n{% endblock\n"];
        yield 'unclosed {% at the end of the file' => ["{% block body %}\n{% endblock"];
        yield 'endblock without a block' => ["{% block body %}{% endblock %}\n{% endblock %}\n"];
        yield 'mismatched endblock name' => ["{% block body %}\n{% endblock content %}\n"];
    }
}
