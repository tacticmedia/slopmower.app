<?php

declare(strict_types=1);

namespace App\Tests\Functional\Git;

use App\Tests\Support\Filesystem\TemporaryDirectories;
use App\Tests\Support\Git\GitRepositoryBuilder;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitRepositoryBuilderTest extends KernelTestCase
{
    use TemporaryDirectories;

    #[TestDox('Equal histories get equal commit SHAs')]
    public function testEqualHistoriesGetEqualCommitShas(): void
    {
        $first = GitRepositoryBuilder::init($this->createTemporaryDirectory('first'));
        $second = GitRepositoryBuilder::init($this->createTemporaryDirectory('second'));

        foreach ([$first, $second] as $builder) {
            $builder->commit(['README.md' => "# Title\n"], 'First commit');
            $builder->commit(['src/a.php' => "<?php\n", 'README.md' => null], 'Second commit');
        }

        self::assertSame($first->resolve('HEAD'), $second->resolve('HEAD'));
        self::assertSame("src/a.php\n", $first->git('ls-tree', '-r', '--name-only', 'HEAD'));
    }

    #[TestDox('Creates, moves, and deletes branches, tags, and refs outside refs/heads')]
    public function testCreatesMovesAndDeletesRefs(): void
    {
        $source = GitRepositoryBuilder::init($this->createTemporaryDirectory('source'));
        $base = $source->commit(['a.txt' => "a\n"]);
        $source->branch('feature')->checkout('feature');
        $featureTip = $source->commit(['b.txt' => "b\n"]);
        $source->tag('v1.0', $featureTip)->tag('v1.1', $featureTip, 'Release 1.1')->updateRef('refs/pull/1/head', $featureTip);
        $source->resetHard($base);
        $rewrittenTip = $source->commit(['c.txt' => "c\n"]);
        $source->checkout('main')->branch('topic')->deleteBranch('topic');

        self::assertNotSame($featureTip, $rewrittenTip);
        self::assertSame($rewrittenTip, $source->resolve('refs/heads/feature'));
        self::assertSame($featureTip, $source->resolve('refs/tags/v1.0'));
        self::assertSame($featureTip, $source->resolve('refs/tags/v1.1^{commit}'));
        self::assertNotSame($featureTip, $source->resolve('refs/tags/v1.1'));
        self::assertSame($featureTip, $source->resolve('refs/pull/1/head'));
        self::assertSame("refs/heads/feature\nrefs/heads/main\n", $source->git('for-each-ref', '--format=%(refname)', 'refs/heads/'));
        self::assertSame('file://'.realpath($source->directory()), $source->fileUrl());
    }
}
