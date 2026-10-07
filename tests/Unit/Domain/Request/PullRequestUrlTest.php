<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Request;

use App\Domain\Repository\RepositoryName;
use App\Domain\Request\PullRequestUrl;
use App\Tests\Support\Domain\InvalidInputAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PullRequestUrlTest extends KernelTestCase
{
    use InvalidInputAssertions;

    #[TestDox('Rejects a URL that is not https, has whitespace, or has more than 2,048 characters')]
    #[DataProvider('invalidUrls')]
    public function testRejectsInvalidUrls(string $url): void
    {
        self::assertInvalidInput(['url'], static fn () => PullRequestUrl::fromString($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'http' => ['http://github.com/acme/billing/pull/42'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'no host' => ['https://'];
        yield 'a space' => ['https://git.example.com/acme/billing/merge requests/42'];
        yield 'a non-breaking space' => ["https://git.example.com/acme/billing/pull/42\u{00A0}"];
        yield '2,049 characters' => ['https://git.example.com/'.str_repeat('ü', 2049 - 24)];
    }

    #[TestDox('Accepts 2,048 characters')]
    public function testAcceptsTheMaximumLength(): void
    {
        $url = 'https://git.example.com/'.str_repeat('ü', 2048 - 24);

        self::assertSame($url, PullRequestUrl::fromString($url)->toString());
    }

    #[TestDox('A GitHub repository accepts only a pull request of its own name, compared case-insensitively, in the canonical form')]
    public function testGitHubRepositoryNeedsItsOwnPullRequest(): void
    {
        $repository = RepositoryName::github('acme/billing');

        self::assertSame('https://github.com/acme/billing/pull/42', PullRequestUrl::forRepository('https://github.com/Acme/Billing/pull/42', $repository)->toString());

        foreach (['https://github.com/acme/ledger/pull/42', 'https://github.com/acme/billing/issues/42', 'https://github.com/acme/billing/pull/42/files', 'https://github.com/acme/billing/pull/0', 'https://gitlab.com/acme/billing/pull/42'] as $foreign) {
            self::assertInvalidInput(['url'], static fn () => PullRequestUrl::forRepository($foreign, $repository));
        }
    }

    #[TestDox('A local repository accepts any https URL')]
    public function testLocalRepositoryAcceptsAnyHttpsUrl(): void
    {
        $url = 'https://git.example.com/acme/billing/-/merge_requests/7';

        self::assertSame($url, PullRequestUrl::forRepository($url, RepositoryName::local('billing'))->toString());
    }
}
