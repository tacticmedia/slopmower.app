<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

use App\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ErrorCodeDocumentationTest extends KernelTestCase
{
    #[TestDox('The error tables of the specifications and the skill have a row for each error code')]
    #[DataProvider('documents')]
    public function testEachErrorCodeHasARow(string $path): void
    {
        $markdown = (string) file_get_contents(dirname(__DIR__, 3)."/{$path}");
        $missing = array_filter(ErrorCode::cases(), static fn (ErrorCode $code): bool => !str_contains($markdown, "\n| `{$code->value}` |"));

        self::assertSame([], array_values(array_map(static fn (ErrorCode $code): string => $code->value, $missing)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'REST' => ['docs/specs/README.md'];
        yield 'MCP' => ['docs/specs/mcp.md'];
        yield 'skill' => ['docs/user/skill/SKILL.md'];
    }
}
