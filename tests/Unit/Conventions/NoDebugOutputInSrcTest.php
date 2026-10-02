<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class NoDebugOutputInSrcTest extends KernelTestCase
{
    private const array DEBUG_FUNCTIONS = ['var_dump', 'print_r', 'dump', 'dd'];

    /** Tokens before a name that make it a method, a declaration, or a class, not a global function call. */
    private const array NOT_A_FUNCTION_CALL = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW];

    #[TestDox('No PHP file in src calls echo, print, var_dump, print_r, dump, or dd')]
    public function testNoSourceFileWritesDebugOutput(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            $tokens = array_values(array_filter(
                \PhpToken::tokenize($file->getContents(), \TOKEN_PARSE),
                static fn (\PhpToken $token): bool => !$token->isIgnorable(),
            ));

            foreach ($tokens as $i => $token) {
                $isOutputStatement = $token->is([\T_ECHO, \T_PRINT, \T_OPEN_TAG_WITH_ECHO]);
                $isDebugFunctionCall = $token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])
                    && in_array(strtolower(ltrim($token->text, '\\')), self::DEBUG_FUNCTIONS, true)
                    && ($tokens[$i + 1] ?? null)?->is('(')
                    && !($tokens[$i - 1] ?? null)?->is(self::NOT_A_FUNCTION_CALL);

                if ($isOutputStatement || $isDebugFunctionCall) {
                    $violations[] = "{$file->getRelativePathname()}:{$token->line} {$token->text}";
                }
            }
        }

        self::assertSame([], $violations);
    }
}
