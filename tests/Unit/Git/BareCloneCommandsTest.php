<?php

declare(strict_types=1);

namespace App\Tests\Unit\Git;

use App\Domain\Git\CommitSha;
use App\Git\BareClone;
use App\Git\GitRemote;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BareCloneCommandsTest extends KernelTestCase
{
    #[TestDox('The heads fetch has the remote configuration, --no-auto-maintenance, --prune, the URL, and both refspecs')]
    public function testHeadsFetchCommand(): void
    {
        $command = BareClone::headsFetchCommand('/srv/clone.git', new GitRemote('file:///repositories/name', ['safe.directory=/repositories/name']));

        self::assertSame([
            '-c', 'safe.directory=/repositories/name',
            'fetch', '--no-auto-maintenance', '--prune',
            '--end-of-options', 'file:///repositories/name', '+refs/heads/*:refs/heads/*', '+refs/tags/*:refs/tags/*',
        ], $command->arguments);
    }

    #[TestDox('The fetch of pending SHAs maps each SHA to its commit pin and fetches no tags')]
    public function testPendingShasFetchCommand(): void
    {
        $first = CommitSha::fromString('1111111111111111111111111111111111111111');
        $second = CommitSha::fromString('2222222222222222222222222222222222222222');

        $command = BareClone::pendingShasFetchCommand('/srv/clone.git', new GitRemote('file:///repositories/name'), [$first, $second]);

        self::assertSame([
            'fetch', '--no-auto-maintenance', '--no-tags', '--end-of-options', 'file:///repositories/name',
            $first.':refs/holistic-review/commits/'.$first,
            $second.':refs/holistic-review/commits/'.$second,
        ], $command->arguments);
    }
}
