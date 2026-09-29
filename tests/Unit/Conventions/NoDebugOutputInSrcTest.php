<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NoDebugOutputInSrcTest extends KernelTestCase
{
    private const array OUTPUT_FUNCTIONS = ['var_dump', 'print_r', 'dump', 'dd'];

    #[TestDox('No PHP file in src calls echo, print, var_dump, print_r, dump, or dd')]
    public function testNoDebugOutputInSrc(): void
    {
        $violations = [];
        foreach (ProjectFiles::find(['src'], ['*.php']) as $path => $file) {
            foreach (self::debugOutputLines($file->getContents()) as $line) {
                $violations[] = $path.':'.$line;
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return list<int>
     */
    private static function debugOutputLines(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $lines = [];
        foreach ($tokens as $index => $token) {
            if ($token->is([\T_ECHO, \T_PRINT, \T_OPEN_TAG_WITH_ECHO]) || self::isOutputFunctionCall($tokens, $index)) {
                $lines[] = $token->line;
            }
        }

        return $lines;
    }

    /**
     * Method calls, static calls, and declarations of the same name do not count.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isOutputFunctionCall(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        return $token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])
            && \in_array(strtolower(ltrim($token->text, '\\')), self::OUTPUT_FUNCTIONS, true)
            && '(' === ($tokens[$index + 1] ?? null)?->text
            && true !== ($tokens[$index - 1] ?? null)?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW]);
    }
}
