<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Git\RefName;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RefNameTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Rejects a leading dash, two dots, @{, control characters, .lock, and spaces')]
    #[DataProvider('invalidRefs')]
    public function testRejectsOptionsAndRefSyntax(string $value): void
    {
        self::assertInvalidInput(['ref'], static fn () => RefName::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRefs(): iterable
    {
        yield 'leading dash' => ['--upload-pack=evil'];
        yield 'two dots' => ['main..feature'];
        yield '@{' => ['main@{1}'];
        yield 'control character' => ["main\x01"];
        yield '.lock' => ['feature/x.lock'];
        yield 'space' => ['my branch'];
        yield 'tilde' => ['main~1'];
        yield 'caret' => ['main^'];
        yield 'colon' => ['a:b'];
        yield 'glob' => ['feature/*'];
        yield 'backslash' => ['a\\b'];
        yield 'single @' => ['@'];
        yield 'component with a leading dot' => ['feature/.hidden'];
        yield 'trailing dot' => ['main.'];
        yield 'empty component' => ['feature//x'];
        yield 'empty' => [''];
        yield '256 characters' => [str_repeat('a', 256)];
    }

    #[TestDox('Accepts main, feature/x, v1.2.3, and a SHA')]
    #[DataProvider('validRefs')]
    public function testAcceptsBranchesTagsAndShas(string $value): void
    {
        self::assertSame($value, RefName::fromString($value)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validRefs(): iterable
    {
        yield 'branch' => ['main'];
        yield 'nested branch' => ['feature/x'];
        yield 'tag' => ['v1.2.3'];
        yield 'full SHA' => ['79f8595a4c1e2b3d5f6071829a3b4c5d6e7f8091'];
    }
}
