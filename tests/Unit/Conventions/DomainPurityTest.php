<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DomainPurityTest extends KernelTestCase
{
    private const array ALLOWED_NAMESPACES = ['App\\Domain', 'Symfony\\Component\\Uid'];

    #[TestDox('src/Domain imports only App\Domain, Symfony\Component\Uid, and PHP core')]
    public function testDomainImportsOnlyAllowedNames(): void
    {
        $violations = [];
        foreach (ProjectFiles::find(['src/Domain'], ['*.php']) as $path => $file) {
            foreach (self::foreignReferences($file->getContents()) as $reference) {
                $violations[] = $path.':'.$reference['line'].' '.$reference['name'];
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function foreignCode(): iterable
    {
        yield 'use import' => ['use Symfony\Component\HttpFoundation\Request;', ['Symfony\Component\HttpFoundation\Request']];
        yield 'group use import' => [
            'use Doctrine\ORM\{Mapping as ORM, function persist, const VERSION};',
            ['Doctrine\ORM\Mapping', 'Doctrine\ORM\persist', 'Doctrine\ORM\VERSION'],
        ];
        yield 'global import outside PHP core' => ['use NoSuchClass; use function trigger_deprecation; use const NO_SUCH_CONSTANT;', ['NoSuchClass', 'trigger_deprecation', 'NO_SUCH_CONSTANT']];
        yield 'namespace that only starts like Uid' => ['use Symfony\Component\UidExtra\Ulid;', ['Symfony\Component\UidExtra\Ulid']];
        yield 'fully qualified name' => ['$clock = new \Symfony\Component\Clock\Clock();', ['Symfony\Component\Clock\Clock']];
        yield 'global function outside PHP core' => ['trigger_deprecation("a", "1.0", "b");', ['trigger_deprecation']];
        yield 'imported function reported once' => ['use function trigger_deprecation as deprecate; deprecate("a", "1.0", "b");', ['trigger_deprecation']];
    }

    /**
     * @param list<string> $expectedNames
     */
    #[DataProvider('foreignCode')]
    #[TestDox('Reports imports, fully qualified names, and global function calls outside the allowed set')]
    public function testReportsForeignNames(string $code, array $expectedNames): void
    {
        self::assertSame($expectedNames, array_column(self::foreignReferences(self::inDomainNamespace($code)), 'name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pureCode(): iterable
    {
        yield 'domain and Uid imports' => ['use App\Domain\Git\CommitSha; use Symfony\Component\Uid\Ulid; use Symfony\Component\Uid;'];
        yield 'core names' => ['use DateTimeImmutable; use function strlen; use const PHP_EOL; $x = new \Random\Randomizer(); \strlen(\PHP_EOL); preg_match("/a/", "a");'];
        yield 'trait and closure use' => ['final class A { use B; public function c(): \Closure { return function () use ($d) {}; } }'];
        yield 'methods named like global functions' => ['final class A { #[\Override] public function &dump(): void { $this->dump(); self::trigger_deprecation(); } }'];
        yield 'attribute' => ['#[Attr(1)] final class A {}'];
    }

    #[DataProvider('pureCode')]
    #[TestDox('Accepts domain, Uid, and core names, trait and closure use, and methods')]
    public function testAcceptsPureCode(string $code): void
    {
        self::assertSame([], self::foreignReferences(self::inDomainNamespace($code)));
    }

    private static function inDomainNamespace(string $code): string
    {
        return "<?php\n\nnamespace App\\Domain\\Probe;\n\n".$code."\n";
    }

    /**
     * @return list<array{line: int, name: string}>
     */
    private static function foreignReferences(string $code): array
    {
        return array_values(array_filter(
            self::references($code),
            static fn (array $reference): bool => !self::isAllowed($reference['name']),
        ));
    }

    /**
     * Use imports, fully qualified names, and unqualified calls that fall back to a global function.
     * Qualified and unqualified class names resolve through the imports or into the file's namespace.
     *
     * @return list<array{line: int, name: string}>
     */
    private static function references(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $namespace = '';
        $importedFunctions = [];
        $braceOpensClassBody = [];
        $nextBraceOpensClassBody = false;
        $references = [];
        for ($index = 0, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            if ($token->is(\T_NAMESPACE) && true === $next?->is([\T_STRING, \T_NAME_QUALIFIED])) {
                $namespace = $next->text;
            } elseif ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && true !== $previous?->is(\T_DOUBLE_COLON)) {
                $nextBraceOpensClassBody = true;
            } elseif ('{' === $token->text || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braceOpensClassBody[] = $nextBraceOpensClassBody;
                $nextBraceOpensClassBody = false;
            } elseif ('}' === $token->text) {
                array_pop($braceOpensClassBody);
            } elseif ($token->is(\T_USE)) {
                // Closure and trait use do not import.
                if ('(' !== $next?->text && true !== end($braceOpensClassBody)) {
                    array_push($references, ...self::imports($tokens, $index, $importedFunctions));
                }
            } elseif ($token->is(\T_NAME_FULLY_QUALIFIED)) {
                $references[] = ['line' => $token->line, 'name' => ltrim($token->text, '\\')];
            } elseif (self::isGlobalFunctionCall($tokens, $index, $namespace, $importedFunctions)) {
                $references[] = ['line' => $token->line, 'name' => $token->text];
            }
        }

        return $references;
    }

    /**
     * Reads the use statement at $index and moves $index to its semicolon.
     *
     * @param list<\PhpToken> $tokens
     * @param list<string>    $importedFunctions lower-case local names
     *
     * @return list<array{line: int, name: string}>
     */
    private static function imports(array $tokens, int &$index, array &$importedFunctions): array
    {
        $statementImportsFunctions = $tokens[$index + 1]->is(\T_FUNCTION);
        $importsFunction = $statementImportsFunctions;
        $groupPrefix = '';
        $imports = [];
        for (++$index; $index < \count($tokens) && ';' !== $tokens[$index]->text; ++$index) {
            $token = $tokens[$index];
            if ($token->is([\T_FUNCTION, \T_CONST])) {
                $importsFunction = $token->is(\T_FUNCTION);
            } elseif (',' === $token->text) {
                $importsFunction = $statementImportsFunctions;
            } elseif ('}' === $token->text) {
                $groupPrefix = '';
            } elseif ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($token->text, '\\');
                if ($tokens[$index + 1]->is(\T_NS_SEPARATOR)) {
                    $groupPrefix = $name.'\\';
                    continue;
                }
                $imports[] = ['line' => $token->line, 'name' => $groupPrefix.$name];
                $localName = array_last(explode('\\', $name));
                if ($tokens[$index + 1]->is(\T_AS)) {
                    $index += 2;
                    $localName = $tokens[$index]->text;
                }
                if ($importsFunction) {
                    $importedFunctions[] = strtolower($localName);
                }
            }
        }

        return $imports;
    }

    /**
     * Method calls, declarations, and names without a global function, such as attribute classes, do not count.
     *
     * @param list<\PhpToken> $tokens
     * @param list<string>    $importedFunctions lower-case local names
     */
    private static function isGlobalFunctionCall(array $tokens, int $index, string $namespace, array $importedFunctions): bool
    {
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;
        if (!$token->is(\T_STRING) || '(' !== ($tokens[$index + 1] ?? null)?->text) {
            return false;
        }
        if (true === $previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW])) {
            return false;
        }
        if (true === $previous?->is(\T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) && true === ($tokens[$index - 2] ?? null)?->is(\T_FUNCTION)) {
            return false;
        }

        $name = strtolower($token->text);
        if (\in_array($name, $importedFunctions, true) || ('' !== $namespace && \function_exists($namespace.'\\'.$name))) {
            return false;
        }

        return \function_exists($name);
    }

    private static function isAllowed(string $name): bool
    {
        foreach (self::ALLOWED_NAMESPACES as $allowedNamespace) {
            if (str_starts_with(strtolower($name.'\\'), strtolower($allowedNamespace.'\\'))) {
                return true;
            }
        }

        return self::isCore($name);
    }

    /**
     * Defined by PHP or one of its extensions, not by userland code such as a polyfill.
     */
    private static function isCore(string $name): bool
    {
        if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false)) {
            return new \ReflectionClass($name)->isInternal();
        }
        if (\function_exists($name)) {
            return new \ReflectionFunction($name)->isInternal();
        }

        return \defined($name) && !\array_key_exists($name, get_defined_constants(true)['user'] ?? []);
    }
}
