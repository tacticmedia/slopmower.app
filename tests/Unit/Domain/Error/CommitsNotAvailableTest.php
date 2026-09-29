<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Error;

use App\Domain\Error\CommitsNotAvailable;
use App\Domain\Git\CommitSha;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitsNotAvailableTest extends KernelTestCase
{
    #[TestDox('Lists each pending commit once in the order given')]
    public function testListsEachPendingCommitOnce(): void
    {
        $sha1Commit = CommitSha::fromString(str_repeat('a', 40));
        $sha256Commit = CommitSha::fromString(str_repeat('b', 64));

        $error = new CommitsNotAvailable([$sha1Commit, $sha256Commit, CommitSha::fromString(str_repeat('a', 40))]);

        self::assertSame(['pendingCommits' => [$sha1Commit, $sha256Commit]], $error->details());
    }

    /**
     * @return iterable<string, array{non-empty-list<CommitSha>, string}>
     */
    public static function pendingCommitCounts(): iterable
    {
        yield 'one commit' => [
            [CommitSha::fromString(str_repeat('c', 40))],
            '1 linked commit is not in the server clone after a fetch. Push it, then try again.',
        ];
        yield 'two commits' => [
            [CommitSha::fromString(str_repeat('c', 40)), CommitSha::fromString(str_repeat('d', 40))],
            '2 linked commits are not in the server clone after a fetch. Push them, then try again.',
        ];
    }

    /**
     * @param non-empty-list<CommitSha> $pendingCommits
     */
    #[DataProvider('pendingCommitCounts')]
    #[TestDox('Counts the pending commits in the message')]
    public function testCountsThePendingCommitsInTheMessage(array $pendingCommits, string $expectedMessage): void
    {
        self::assertSame($expectedMessage, new CommitsNotAvailable($pendingCommits)->getMessage());
    }
}
