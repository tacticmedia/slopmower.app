<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

final class DomainPurityTest extends KernelTestCase
{
    private const array ALLOWED_PREFIXES = ['App\\Domain\\', 'Symfony\\Component\\Uid\\'];

    /** Tokens before a name that make it a method, a declaration, or a class, not a global function call. */
    private const array NOT_A_FUNCTION_CALL = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW];

    #[TestDox('src/Domain imports only App\Domain, Symfony\Component\Uid, and PHP core')]
    public function testDomainHasNoFrameworkDependency(): void
    {
        $violations = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/src/Domain')->name('*.php') as $file) {
            foreach (self::referencedNames($file->getContents()) as $name) {
                if (!self::isAllowed($name)) {
                    $violations[] = "{$file->getRelativePathname()}: {$name}";
                }
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * The namespace with a trailing separator, the top-level imports, the fully qualified names,
     * and the unqualified calls of global functions that are not in the file's namespace.
     *
     * @return list<string>
     */
    private static function referencedNames(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code, \TOKEN_PARSE),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $names = [];
        $namespace = '';
        $braceDepth = 0;

        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;

            if ($token->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$braceDepth;
            } elseif ($token->is('}')) {
                --$braceDepth;
            } elseif ($token->is(\T_NAMESPACE) && null !== $next && $next->is([\T_NAME_QUALIFIED, \T_STRING])) {
                $namespace = $next->text;
                $names[] = $namespace.'\\';
            } elseif ($token->is(\T_USE) && 0 === $braceDepth && null !== $next && !$next->is('(')) {
                [$imports, $i] = self::importsOfUseStatement($tokens, $i + 1);
                array_push($names, ...$imports);
            } elseif ($token->is(\T_NAME_FULLY_QUALIFIED)) {
                $names[] = ltrim($token->text, '\\');
            } elseif (
                $token->is(\T_STRING)
                && null !== $next && $next->is('(')
                && !($tokens[$i - 1] ?? null)?->is(self::NOT_A_FUNCTION_CALL)
                && !function_exists("{$namespace}\\{$token->text}")
                && function_exists($token->text)
            ) {
                $names[] = $token->text;
            }
        }

        return $names;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return array{list<string>, int} The imported names and the index of the closing semicolon
     */
    private static function importsOfUseStatement(array $tokens, int $start): array
    {
        $imports = [];
        $groupPrefix = null;
        $isAlias = false;

        for ($i = $start; !$tokens[$i]->is(';'); ++$i) {
            $token = $tokens[$i];

            if ($token->is('{')) {
                $groupPrefix = array_pop($imports);
            } elseif ($token->is(\T_AS)) {
                $isAlias = true;
            } elseif ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                if (!$isAlias) {
                    $name = ltrim($token->text, '\\');
                    $imports[] = null === $groupPrefix ? $name : "{$groupPrefix}\\{$name}";
                }

                $isAlias = false;
            }
        }

        return [$imports, $i];
    }

    private static function isAllowed(string $name): bool
    {
        if (array_any(self::ALLOWED_PREFIXES, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
            return true;
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
            return new \ReflectionClass($name)->isInternal();
        }

        if (function_exists($name)) {
            return new \ReflectionFunction($name)->isInternal();
        }

        return defined($name) && !array_key_exists($name, get_defined_constants(true)['user'] ?? []);
    }
}
