<?php

declare(strict_types=1);

namespace App\Tests\Unit\GitHub;

use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\GitHub\GitBackedGitHub;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

/**
 * The git-backed fake against the answers of GitHub for octocat/Hello-World, recorded on 2026-11-04 and cut to the
 * fields that {@see \App\GitHub\GitHubCodeReader} reads. The bundle holds the same repository.
 */
final class GitBackedGitHubTest extends KernelTestCase
{
    use TemporaryDirectories;

    private const string FIXTURES = __DIR__.'/../../Fixtures/github';

    #[TestDox('The fake answers each recorded read of a public repository as GitHub does')]
    public function testFakeAnswersAsGitHub(): void
    {
        $gitDir = $this->temporaryDirectory().'/Hello-World.git';
        self::git(['init', '--quiet', '--bare', $gitDir]);
        self::git(['--git-dir='.$gitDir, 'fetch', '--quiet', self::FIXTURES.'/hello-world.bundle', '+refs/*:refs/*']);
        $fake = new GitBackedGitHub($gitDir, 'https://api.github.com/repos/octocat/Hello-World/');
        /** @var array{cases: list<array{path: string, accept: string|null, status: int, body: mixed, variables?: array<string, string>}>} $recorded */
        $recorded = json_decode((string) file_get_contents(self::FIXTURES.'/hello-world-code-reads.json'), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($recorded['cases'] as $case) {
            $answer = 'graphql' === $case['path'] ? $fake->blame(['variables' => $case['variables'] ?? []]) : $fake->answer($case['path'], ['accept' => (string) $case['accept']]);
            $response = new MockHttpClient($answer ?? new MockResponse('', ['http_code' => 599]))->request('GET', 'https://api.github.com/');
            $content = $response->getContent(false);
            $actual = \is_string($case['body']) ? $content : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
            $expected = $case['body'];

            if ('graphql' === $case['path']) {
                [$expected, $actual] = [self::blameRanges($expected), self::blameRanges($actual)];
            }

            self::assertSame($case['status'], $response->getStatusCode(), $case['path']);
            self::assertEquals($expected, self::projected($actual, $expected), $case['path']);
        }
    }

    /**
     * $actual with only the keys of $expected, recursively.
     */
    private static function projected(mixed $actual, mixed $expected): mixed
    {
        if (!\is_array($actual) || !\is_array($expected)) {
            return $actual;
        }

        if (array_is_list($expected)) {
            return array_map(static fn (mixed $item, int $index): mixed => self::projected($item, $expected[$index] ?? $expected[0] ?? null), $actual, array_keys($actual));
        }

        return array_map(static fn (string|int $key): mixed => self::projected($actual[$key] ?? null, $expected[$key]), array_combine(array_keys($expected), array_keys($expected)));
    }

    /**
     * @return list<array{int, int, string, string, string, int}> Lines, commit, first message line, author, and author time
     */
    private static function blameRanges(mixed $body): array
    {
        $ranges = \is_array($body) ? $body['data']['repository']['object']['blame']['ranges'] ?? [] : [];

        return array_values(array_map(static fn (array $range): array => [
            $range['startingLine'],
            $range['endingLine'],
            $range['commit']['oid'],
            explode("\n", $range['commit']['message'])[0],
            $range['commit']['author']['name'],
            new \DateTimeImmutable($range['commit']['author']['date'])->getTimestamp(),
        ], \is_array($ranges) ? $ranges : []));
    }

    /**
     * @param list<string> $arguments
     */
    private static function git(array $arguments): void
    {
        new Process(['git', ...$arguments], env: ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'])->mustRun();
    }
}
