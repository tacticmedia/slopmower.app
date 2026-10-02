<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class NoProcessOutsideGitTest extends KernelTestCase
{
    private const array PROCESS_FUNCTIONS = ['exec', 'passthru', 'pcntl_exec', 'popen', 'proc_open', 'shell_exec', 'system'];

    /** Tokens before a name that make it a method, a declaration, or a class, not a global function call. */
    private const array NOT_A_FUNCTION_CALL = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW];

    #[TestDox('No file in src outside src/Git creates a Process')]
    public function testOnlyGitCodeStartsProcesses(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/src')->notPath('#^Git/#')->name('*.php') as $file) {
            $tokens = array_values(array_filter(
                \PhpToken::tokenize($file->getContents(), \TOKEN_PARSE),
                static fn (\PhpToken $token): bool => !$token->isIgnorable(),
            ));

            foreach ($tokens as $i => $token) {
                $usesProcessComponent = $token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])
                    && str_starts_with(ltrim($token->text, '\\'), 'Symfony\Component\Process\\');
                $callsProcessFunction = $token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])
                    && in_array(strtolower(ltrim($token->text, '\\')), self::PROCESS_FUNCTIONS, true)
                    && ($tokens[$i + 1] ?? null)?->is('(')
                    && !($tokens[$i - 1] ?? null)?->is(self::NOT_A_FUNCTION_CALL);

                if ($usesProcessComponent || $callsProcessFunction || $token->is('`')) {
                    $violations[] = "{$file->getRelativePathname()}:{$token->line} {$token->text}";
                }
            }
        }

        self::assertSame([], $violations);
    }
}
