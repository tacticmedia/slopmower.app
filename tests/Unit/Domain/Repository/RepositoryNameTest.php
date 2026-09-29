<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Repository;

use App\Domain\Error\InvalidInput;
use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\RepositorySource;
use App\Tests\Unit\Domain\ViolatedFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryNameTest extends KernelTestCase
{
    use ViolatedFields;

    /**
     * @return iterable<string, array{RepositorySource, string}>
     */
    public static function validNames(): iterable
    {
        yield 'GitHub' => [RepositorySource::GitHub, 'octo-org/hello-world'];
        yield 'GitHub, 255 characters' => [RepositorySource::GitHub, 'o/'.str_repeat('n', 253)];
        yield 'local' => [RepositorySource::Local, 'hello-world'];
        yield 'local, 255 multibyte characters' => [RepositorySource::Local, str_repeat("\u{00E9}", 255)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localNamesWithASlashOrALeadingDot(): iterable
    {
        yield 'owner/name' => ['octo-org/hello-world'];
        yield 'leading dot' => ['.hello'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherInvalidLocalNames(): iterable
    {
        yield 'empty' => [''];
        yield '256 multibyte characters' => [str_repeat("\u{00E9}", 256)];
        yield 'NUL byte' => ["a\0b"];
        yield 'trailing newline' => ["hello\n"];
        yield 'invalid UTF-8' => ["caf\xe9"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGitHubNames(): iterable
    {
        yield 'no slash' => ['hello-world'];
        yield 'two slashes' => ['octo-org/hello/world'];
        yield 'empty owner' => ['/hello-world'];
        yield 'empty name' => ['octo-org/'];
        yield '256 characters' => ['o/'.str_repeat('n', 254)];
        yield 'invalid UTF-8' => ["octo/caf\xe9"];
    }

    #[DataProvider('validNames')]
    #[TestDox('Accepts owner/name for GitHub and a plain directory name for local')]
    public function testAcceptsOwnerNameForGitHubAndAPlainDirectoryNameForLocal(RepositorySource $source, string $value): void
    {
        $name = RepositoryName::fromSource($source, $value);

        self::assertSame($source, $name->source());
        self::assertSame($value, $name->toString());
        self::assertSame($value, (string) $name);
        self::assertTrue($name->equals(RepositorySource::GitHub === $source ? RepositoryName::gitHub($value) : RepositoryName::local($value)));
    }

    #[DataProvider('localNamesWithASlashOrALeadingDot')]
    #[TestDox('Rejects a local name with a slash or a leading dot')]
    public function testRejectsALocalNameWithASlashOrALeadingDot(string $value): void
    {
        $this->expectException(InvalidInput::class);

        RepositoryName::local($value);
    }

    #[DataProvider('otherInvalidLocalNames')]
    #[TestDox('Rejects a local name that is empty, too long, has a control character, or is not UTF-8')]
    public function testRejectsOtherInvalidLocalNames(string $value): void
    {
        $this->expectException(InvalidInput::class);

        RepositoryName::local($value);
    }

    #[DataProvider('invalidGitHubNames')]
    #[TestDox('Rejects a GitHub name that is not owner/name, is longer than 255 characters, or is not UTF-8')]
    public function testRejectsInvalidGitHubNames(string $value): void
    {
        $this->expectException(InvalidInput::class);

        RepositoryName::gitHub($value);
    }

    #[TestDox('Applies the rules of the given source')]
    public function testAppliesTheRulesOfTheGivenSource(): void
    {
        self::assertSame(['name'], self::violatedFields(static fn (): RepositoryName => RepositoryName::fromSource(RepositorySource::Local, 'octo-org/hello-world')));
        self::assertSame(['name'], self::violatedFields(static fn (): RepositoryName => RepositoryName::fromSource(RepositorySource::GitHub, 'hello-world')));
    }

    #[TestDox('Equals a name of the same source and the same characters only')]
    public function testEqualsANameOfTheSameSourceAndTheSameCharactersOnly(): void
    {
        self::assertTrue(RepositoryName::local('hello')->equals(RepositoryName::local('hello')));
        self::assertFalse(RepositoryName::local('hello')->equals(RepositoryName::local('Hello')));
        self::assertFalse(RepositoryName::gitHub('octo/hello')->equals(RepositoryName::gitHub('Octo/Hello')));
    }

    #[TestDox('Names name or the given field in the violation')]
    public function testNamesNameOrTheGivenFieldInTheViolation(): void
    {
        self::assertSame(['name'], self::violatedFields(static fn (): RepositoryName => RepositoryName::local('.x')));
        self::assertSame(['directory'], self::violatedFields(static fn (): RepositoryName => RepositoryName::local('.x', 'directory')));
        self::assertSame(['repository'], self::violatedFields(static fn (): RepositoryName => RepositoryName::gitHub('x', 'repository')));
        self::assertSame(['repository'], self::violatedFields(static fn (): RepositoryName => RepositoryName::fromSource(RepositorySource::Local, '.x', 'repository')));
    }
}
