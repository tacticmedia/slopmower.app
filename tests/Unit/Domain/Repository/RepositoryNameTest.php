<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Repository;

use App\Domain\Repository\RepositoryName;
use App\Domain\Repository\RepositorySource;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryNameTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Accepts owner/name for GitHub and a plain directory name for local')]
    public function testAcceptsNamesPerSource(): void
    {
        $github = RepositoryName::forSource(RepositorySource::Github, 'tactic-media/slop.mower_2');
        $local = RepositoryName::forSource(RepositorySource::Local, 'Slopmower (copy)');

        self::assertSame(RepositorySource::Github, $github->source());
        self::assertSame('tactic-media/slop.mower_2', $github->toString());
        self::assertSame(RepositorySource::Local, $local->source());
        self::assertSame('Slopmower (copy)', $local->toString());
    }

    #[TestDox('Rejects a local name with a slash or a leading dot')]
    #[DataProvider('invalidLocalNames')]
    public function testRejectsInvalidLocalNames(string $value): void
    {
        self::assertInvalidInput(['directory'], static fn () => RepositoryName::local($value, 'directory'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLocalNames(): iterable
    {
        yield 'slash' => ['owner/name'];
        yield 'leading dot' => ['.git'];
        yield 'control character' => ["repo\x1f"];
        yield 'empty' => [''];
        yield '256 characters' => [str_repeat('a', 256)];
    }

    #[TestDox('Rejects a GitHub name without exactly one slash between two names')]
    #[DataProvider('invalidGithubNames')]
    public function testRejectsInvalidGithubNames(string $value): void
    {
        self::assertInvalidInput(['name'], static fn () => RepositoryName::github($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGithubNames(): iterable
    {
        yield 'no slash' => ['slopmower'];
        yield 'two slashes' => ['a/b/c'];
        yield 'empty owner' => ['/name'];
        yield 'dot dot name' => ['owner/..'];
        yield 'space' => ['owner/my repo'];
    }

    #[TestDox('Compares GitHub names case-insensitively and local names exactly')]
    public function testComparesNamesPerSource(): void
    {
        self::assertTrue(RepositoryName::github('Owner/Repo')->equals(RepositoryName::github('owner/repo')));
        self::assertFalse(RepositoryName::local('Repo')->equals(RepositoryName::local('repo')));
    }
}
