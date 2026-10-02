<?php

declare(strict_types=1);

namespace App\Tests\Functional\Docs;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The converter configuration lives in config/packages/twig_extra.yaml, so the test renders through the booted Twig.
 */
final class MarkdownSafetyTest extends KernelTestCase
{
    #[TestDox('Raw HTML is escaped')]
    public function testRawHtmlIsEscaped(): void
    {
        $html = self::render("<script>alert(1)</script>\n\nText <img src=x onerror=alert(1)>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[TestDox('javascript: links are dropped')]
    public function testJavascriptLinksAreDropped(): void
    {
        $html = self::render('[click](javascript:alert(1)) and <javascript:alert(2)>');

        self::assertStringNotContainsString('href', $html);
        self::assertStringContainsString('<a>click</a>', $html);
    }

    #[TestDox('GFM tables render')]
    public function testGfmTablesRender(): void
    {
        $html = self::render("| Code | Meaning |\n|---|---|\n| `not_found` | Missing |");

        self::assertStringContainsString('<td><code>not_found</code></td>', $html);
    }

    private static function render(string $markdown): string
    {
        $twig = self::getContainer()->get(Environment::class);

        return $twig->createTemplate('{{ markdown|markdown_to_html }}')->render(['markdown' => $markdown]);
    }
}
