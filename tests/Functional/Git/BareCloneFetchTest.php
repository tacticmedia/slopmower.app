<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Domain\Git\CommitSha;
use App\Git\BareClone;
use App\Git\GitRunner;
use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BareCloneFetchTest extends KernelTestCase
{
    use TemporaryDirectories;

    #[TestDox('After a fetch only refs/heads, refs/tags, and refs/slopmower exist')]
    public function testFetchWritesOnlyHeadsTagsAndPins(): void
    {
        $source = GitRepositoryBuilder::init($this->temporaryDirectory());
        $source->commit(['README.md' => "Hello\n"]);
        $source->git('tag', 'v1');
        $source->git('notes', 'add', '--message=Note');
        $pullHead = $source->switch('pull')->commit(['pull.txt' => "Pull\n"]);
        $source->git('update-ref', 'refs/pull/1/head', $pullHead);
        $source->switch('main')->git('branch', '--delete', '--force', 'pull');
        $source->switch('develop');

        $git = new GitRunner(new NullLogger());
        $bareClone = new BareClone($git);
        $clone = $this->temporaryDirectory().'/tenant/repositories/clone.git';
        $bareClone->init($clone);
        $bareClone->fetch($clone, $source->url(), [CommitSha::fromString($pullHead)]);

        self::assertSame(
            ['refs/heads/develop', 'refs/heads/main', "refs/slopmower/commits/{$pullHead}", 'refs/tags/v1'],
            explode("\n", trim($git->run($clone, ['for-each-ref', '--format=%(refname)']))),
        );
        self::assertSame("refs/heads/develop\n", $git->run($clone, ['symbolic-ref', 'HEAD']));
    }
}
