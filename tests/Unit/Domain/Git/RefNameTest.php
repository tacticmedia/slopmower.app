<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Git;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\RefName;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RefNameTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectingRefs(): iterable
    {
        yield 'leading dash' => ['-x'];
        yield 'two dots' => ['a..b'];
        yield '@{' => ['a@{b'];
        yield 'NUL byte' => ["a\0b"];
        yield '.lock' => ['a.lock'];
        yield '.lock before a slash' => ['a.lock/b'];
        yield 'space' => ['a b'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherInvalidRefs(): iterable
    {
        yield 'empty' => [''];
        yield '@ alone' => ['@'];
        yield 'backslash' => ['a\\b'];
        yield 'leading dot' => ['.a'];
        yield 'segment with a leading dot' => ['a/.b'];
        yield 'trailing dot' => ['x.'];
        yield 'leading slash' => ['/a'];
        yield 'trailing slash' => ['a/'];
        yield 'empty segment' => ['a//b'];
        yield '256 multibyte characters' => [str_repeat("\u{00E9}", 256)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validRefs(): iterable
    {
        yield 'main' => ['main'];
        yield 'feature/x' => ['feature/x'];
        yield 'v1.2.3' => ['v1.2.3'];
        yield 'SHA-1' => ['d0b05a7b5719fe83d400048e5ebe6009b273740d'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusualValidRefs(): iterable
    {
        yield '@ inside a name' => ['a@b'];
        yield '.lock inside a segment' => ['a/b.lock.c'];
        yield 'segment that ends with a dot' => ['a./b'];
        yield 'dash after the start' => ['release-1.0'];
    }

    #[DataProvider('injectingRefs')]
    #[TestDox('Rejects a leading dash, two dots, @{, control characters, .lock, and spaces')]
    public function testRejectsOptionsAndRefSyntax(string $value): void
    {
        $this->expectException(InvalidInput::class);

        RefName::fromString($value);
    }

    #[DataProvider('validRefs')]
    #[TestDox('Accepts main, feature/x, v1.2.3, and a SHA')]
    public function testAcceptsMainFeatureXV123AndASha(string $value): void
    {
        $ref = RefName::fromString($value);

        self::assertSame($value, $ref->toString());
        self::assertSame($value, (string) $ref);
        self::assertTrue($ref->equals(RefName::fromString($value)));
    }

    #[DataProvider('otherInvalidRefs')]
    #[TestDox('Rejects the other rules of git check-ref-format and 256 characters')]
    public function testRejectsOtherInvalidRefs(string $value): void
    {
        $this->expectException(InvalidInput::class);

        RefName::fromString($value);
    }

    #[DataProvider('unusualValidRefs')]
    #[TestDox('Accepts @ and .lock inside a name, a dot before a slash, and an inner dash')]
    public function testAcceptsUnusualValidRefs(string $value): void
    {
        self::assertSame($value, RefName::fromString($value)->toString());
    }

    #[TestDox('Accepts 255 multibyte characters, the ref limit of docs/specs')]
    public function testAccepts255MultibyteCharacters(): void
    {
        $value = str_repeat("\u{00E9}", 255);

        self::assertSame($value, RefName::fromString($value)->toString());
    }

    #[TestDox('Equals only the same name, case-sensitively')]
    public function testEqualsOnlyTheSameName(): void
    {
        self::assertFalse(RefName::fromString('Main')->equals(RefName::fromString('main')));
    }

    #[TestDox('Names ref or the given field in the violation')]
    public function testNamesRefOrTheGivenFieldInTheViolation(): void
    {
        self::assertSame(['ref'], self::violatedFields(static fn (): RefName => RefName::fromString('-x')));
        self::assertSame(['branch'], self::violatedFields(static fn (): RefName => RefName::fromString('-x', 'branch')));
    }
}
