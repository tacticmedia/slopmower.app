<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Git runs only through {@see \App\Git\GitRunner}: outside src/Git, no Process component and no PHP process function.
 */
final class NoProcessOutsideGitTest extends KernelTestCase
{
    private const string PROCESS_NAMESPACE = 'symfony\\component\\process';
    private const array PROCESS_FUNCTIONS = ['exec', 'passthru', 'pcntl_exec', 'popen', 'proc_open', 'shell_exec', 'system'];
    private const string BACKTICK_OPERATOR = 'backtick operator';

    #[TestDox('No file in src outside src/Git creates a Process')]
    public function testNoFileOutsideSrcGitCreatesAProcess(): void
    {
        $violations = [];
        foreach (ProjectFiles::find(['src'], ['*.php']) as $path => $file) {
            if (str_starts_with($path, 'src/Git/')) {
                continue;
            }
            foreach (self::processReferences($file->getContents()) as $reference) {
                $violations[] = $path.':'.$reference['line'].' '.$reference['name'];
            }
        }

        self::assertSame([], $violations);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function processCode(): iterable
    {
        yield 'use import' => ['use Symfony\Component\Process\Process; new Process(["git"]);', ['Symfony\Component\Process\Process']];
        yield 'use import with an alias' => ['use Symfony\Component\Process\Process as Runner;', ['Symfony\Component\Process\Process']];
        yield 'group use import' => ['use Symfony\Component\{Finder\Finder, Process\Process};', ['Symfony\Component\Process\Process']];
        yield 'fully qualified name' => ['\Symfony\Component\Process\Process::fromShellCommandline("git");', ['Symfony\Component\Process\Process']];
        yield 'qualified name through an imported namespace' => [
            'use Symfony\Component as Components; new Components\Process\InputStream();',
            ['Symfony\Component\Process\InputStream'],
        ];
        yield 'PHP process functions' => [
            'exec("git"); \shell_exec("git"); proc_open([], [], $pipes); popen("git", "r"); System("git"); passthru("git"); pcntl_exec("/usr/bin/git"); $f = exec(...);',
            ['exec', 'shell_exec', 'proc_open', 'popen', 'System', 'passthru', 'pcntl_exec', 'exec'],
        ];
        yield 'backtick operator' => ['$log = `git log`;', [self::BACKTICK_OPERATOR]];
    }

    /**
     * @param list<string> $expectedNames
     */
    #[DataProvider('processCode')]
    #[TestDox('Reports Process imports, fully qualified and qualified names, PHP process functions, and backticks')]
    public function testReportsProcessReferences(string $code, array $expectedNames): void
    {
        self::assertSame($expectedNames, array_column(self::processReferences(self::inNamespace($code)), 'name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherCode(): iterable
    {
        yield 'other components' => ['use Symfony\Component\Finder\Finder; use Symfony\Component\ProcessExtra\Process; new \Symfony\Component\ProcessExtra\Process();'];
        yield 'methods named like process functions' => ['final class A { public function exec(): void { $this->exec(); $this?->system(); self::popen(); } }'];
        yield 'classes named like process functions' => ['#[System] final class A { public function b(): object { return new System(); } }'];
        yield 'closure use and strings' => ['$run = function () use ($process): string { return "exec proc_open"; };'];
    }

    #[DataProvider('otherCode')]
    #[TestDox('Accepts other components, methods and classes named like process functions, closure use, and strings')]
    public function testAcceptsOtherCode(string $code): void
    {
        self::assertSame([], self::processReferences(self::inNamespace($code)));
    }

    private static function inNamespace(string $code): string
    {
        return "<?php\n\nnamespace App\\Probe;\n\n".$code."\n";
    }

    /**
     * @return list<array{line: int, name: string}>
     */
    private static function processReferences(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $importedNames = [];
        $insideBackticks = false;
        $references = [];
        for ($index = 0, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ('`' === $token->text) {
                $insideBackticks = !$insideBackticks;
                if ($insideBackticks) {
                    $references[] = ['line' => $token->line, 'name' => self::BACKTICK_OPERATOR];
                }
            } elseif ($token->is(\T_USE) && '(' !== ($tokens[$index + 1] ?? null)?->text) {
                foreach (self::imports($tokens, $index) as $alias => $import) {
                    $importedNames[$alias] = $import['name'];
                    $references[] = $import;
                }
            } elseif ($token->is(\T_NAME_FULLY_QUALIFIED)) {
                $references[] = ['line' => $token->line, 'name' => ltrim($token->text, '\\')];
            } elseif ($token->is(\T_NAME_QUALIFIED)) {
                [$firstSegment, $rest] = explode('\\', $token->text, 2);
                $references[] = ['line' => $token->line, 'name' => ($importedNames[strtolower($firstSegment)] ?? $firstSegment).'\\'.$rest];
            } elseif (self::isFunctionCall($tokens, $index)) {
                $references[] = ['line' => $token->line, 'name' => $token->text];
            }
        }

        return array_values(array_filter(
            $references,
            static fn (array $reference): bool => self::BACKTICK_OPERATOR === $reference['name'] || self::isProcessName($reference['name']),
        ));
    }

    /**
     * Reads the use statement at $index and moves $index to its semicolon.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array<string, array{line: int, name: string}> keyed by the lower-case local name
     */
    private static function imports(array $tokens, int &$index): array
    {
        $groupPrefix = '';
        $imports = [];
        for (++$index; $index < \count($tokens) && ';' !== $tokens[$index]->text; ++$index) {
            $token = $tokens[$index];
            if ('}' === $token->text) {
                $groupPrefix = '';
            } elseif ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($token->text, '\\');
                if (($tokens[$index + 1] ?? null)?->is(\T_NS_SEPARATOR)) {
                    $groupPrefix = $name.'\\';
                    continue;
                }
                $localName = array_last(explode('\\', $name));
                if (($tokens[$index + 1] ?? null)?->is(\T_AS)) {
                    $index += 2;
                    $localName = $tokens[$index]->text;
                }
                $imports[strtolower($localName)] = ['line' => $token->line, 'name' => $groupPrefix.$name];
            }
        }

        return $imports;
    }

    /**
     * Method calls, declarations, and class names do not count.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isFunctionCall(array $tokens, int $index): bool
    {
        $previous = $tokens[$index - 1] ?? null;

        return $tokens[$index]->is(\T_STRING)
            && '(' === ($tokens[$index + 1] ?? null)?->text
            && true !== $previous?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_ATTRIBUTE]);
    }

    private static function isProcessName(string $name): bool
    {
        $lowerCaseName = strtolower($name);

        return self::PROCESS_NAMESPACE === $lowerCaseName
            || str_starts_with($lowerCaseName, self::PROCESS_NAMESPACE.'\\')
            || \in_array($lowerCaseName, self::PROCESS_FUNCTIONS, true);
    }
}
